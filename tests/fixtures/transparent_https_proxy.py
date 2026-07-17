#!/usr/bin/env python3
"""Loopback-only HTTPS measurement proxy for the JM deterministic fixture.

This is deliberately not a general-purpose proxy. It accepts a fixed set of
JM CONNECT targets, terminates TLS with an ephemeral CA, and forwards the
validated HTTP request to one fixed loopback fixture.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import secrets
import signal
import socket
import socketserver
import ssl
import sys
import threading
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Final
from urllib.parse import urlsplit

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import ExtendedKeyUsageOID, NameOID


API_HOSTS: Final[frozenset[str]] = frozenset(
    {
        "www.cdnhjk.net",
        "www.cdngwc.cc",
        "www.cdngwc.net",
        "www.cdngwc.club",
        "www.cdnutc.me",
    }
)
CONFIG_HOSTS: Final[frozenset[str]] = frozenset(
    {
        "rup4a04-c01.tos-ap-southeast-1.bytepluses.com",
        "rup4a04-c02.tos-cn-hongkong.bytepluses.com",
        "rup4a04-c03.tos-cn-beijing.bytepluses.com.cn",
    }
)
CDN_HOSTS: Final[frozenset[str]] = frozenset(
    {
        "cdn-msp.jmapiproxy1.cc",
        "cdn-msp.jmapiproxy2.cc",
        "cdn-msp2.jmapiproxy2.cc",
        "cdn-msp3.jmapiproxy2.cc",
        "cdn-msp.jmapinodeudzn.net",
        "cdn-msp3.jmapinodeudzn.net",
    }
)
ALLOWED_HOSTS: Final[tuple[str, ...]] = tuple(sorted(API_HOSTS | CONFIG_HOSTS | CDN_HOSTS))
API_PATHS: Final[frozenset[str]] = frozenset(
    {
        "/album",
        "/chapter",
        "/chapter_view_template",
        "/comic_read",
        "/latest",
        "/search",
        "/categories/filter",
        "/promote",
        "/promote_list",
        "/week",
        "/week/filter",
    }
)
HOST_PATTERN: Final[re.Pattern[str]] = re.compile(
    r"^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$"
)
HEADER_NAME_PATTERN: Final[re.Pattern[str]] = re.compile(r"^[!#$%&'*+.^_`|~0-9A-Za-z-]+$")
MAX_HEADER_BYTES: Final[int] = 64 * 1024
MAX_RESPONSE_BYTES: Final[int] = 32 * 1024 * 1024
SOCKET_TIMEOUT_SECONDS: Final[float] = 12.0
MAX_CONCURRENT_CONNECTIONS: Final[int] = 32


class ProxyProtocolError(Exception):
    def __init__(self, status: int, message: str) -> None:
        super().__init__(message)
        self.status = status
        self.message = message


@dataclass(frozen=True)
class ProxyConfig:
    listen_host: str
    listen_port: int
    upstream_host: str
    upstream_port: int
    work_dir: Path
    state_file: Path


def normalize_host(raw: str) -> str:
    value = raw.strip().rstrip(".").lower()
    if not value or any(ord(character) > 127 for character in value):
        raise ValueError("host must be non-empty ASCII")
    if not HOST_PATTERN.fullmatch(value):
        raise ValueError("host has invalid syntax")
    return value


def parse_authority(raw: str) -> tuple[str, int]:
    if raw.count(":") != 1:
        raise ValueError("authority must contain one host:port pair")
    host_raw, port_raw = raw.rsplit(":", 1)
    host = normalize_host(host_raw)
    if not port_raw.isdigit():
        raise ValueError("authority port is invalid")
    return host, int(port_raw)


def parse_host_header(raw: str) -> tuple[str, int]:
    value = raw.strip()
    if ":" not in value:
        return normalize_host(value), 443
    return parse_authority(value)


def validate_config(arguments: argparse.Namespace) -> ProxyConfig:
    if arguments.listen_host != "127.0.0.1":
        raise ValueError("listen-host must be exactly 127.0.0.1")
    if arguments.upstream_host != "127.0.0.1":
        raise ValueError("upstream-host must be exactly 127.0.0.1")
    for label, port in (("listen-port", arguments.listen_port), ("upstream-port", arguments.upstream_port)):
        if not 1 <= port <= 65535:
            raise ValueError(f"{label} must be between 1 and 65535")
    if arguments.listen_port == arguments.upstream_port:
        raise ValueError("listen-port and upstream-port must differ")

    work_dir = Path(arguments.work_dir).expanduser().resolve()
    state_file = Path(arguments.state_file).expanduser().resolve()
    try:
        state_file.relative_to(work_dir)
    except ValueError as error:
        raise ValueError("state-file must be inside work-dir") from error
    return ProxyConfig(
        listen_host=arguments.listen_host,
        listen_port=arguments.listen_port,
        upstream_host=arguments.upstream_host,
        upstream_port=arguments.upstream_port,
        work_dir=work_dir,
        state_file=state_file,
    )


def write_private_file(path: Path, content: bytes) -> None:
    path.write_bytes(content)
    try:
        path.chmod(0o600)
    except OSError:
        pass


def generate_ephemeral_certificates(work_dir: Path) -> dict[str, str]:
    work_dir.mkdir(parents=True, exist_ok=True)
    ca_key_path = work_dir / "measurement-ca-key.pem"
    ca_cert_path = work_dir / "measurement-ca.pem"
    leaf_key_path = work_dir / "measurement-server-key.pem"
    leaf_cert_path = work_dir / "measurement-server.pem"
    for path in (ca_key_path, ca_cert_path, leaf_key_path, leaf_cert_path):
        if path.exists():
            raise ValueError(f"certificate output already exists: {path}")

    now = datetime.now(timezone.utc)
    ca_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    ca_name = x509.Name(
        [x509.NameAttribute(NameOID.COMMON_NAME, f"JM Measurement Root {os.getpid()}")]
    )
    ca_certificate = (
        x509.CertificateBuilder()
        .subject_name(ca_name)
        .issuer_name(ca_name)
        .public_key(ca_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - timedelta(minutes=5))
        .not_valid_after(now + timedelta(hours=24))
        .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=False,
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=True,
                crl_sign=True,
                encipher_only=None,
                decipher_only=None,
            ),
            critical=True,
        )
        .add_extension(x509.SubjectKeyIdentifier.from_public_key(ca_key.public_key()), critical=False)
        .sign(ca_key, hashes.SHA256())
    )

    leaf_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    leaf_name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, ALLOWED_HOSTS[0])])
    leaf_certificate = (
        x509.CertificateBuilder()
        .subject_name(leaf_name)
        .issuer_name(ca_certificate.subject)
        .public_key(leaf_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - timedelta(minutes=5))
        .not_valid_after(now + timedelta(hours=24))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=False,
                key_encipherment=True,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=None,
                decipher_only=None,
            ),
            critical=True,
        )
        .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.SERVER_AUTH]), critical=False)
        .add_extension(
            x509.SubjectAlternativeName([x509.DNSName(host) for host in ALLOWED_HOSTS]),
            critical=False,
        )
        .add_extension(x509.SubjectKeyIdentifier.from_public_key(leaf_key.public_key()), critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_public_key(ca_key.public_key()), critical=False)
        .sign(ca_key, hashes.SHA256())
    )

    write_private_file(
        ca_key_path,
        ca_key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        ),
    )
    write_private_file(ca_cert_path, ca_certificate.public_bytes(serialization.Encoding.PEM))
    write_private_file(
        leaf_key_path,
        leaf_key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        ),
    )
    write_private_file(leaf_cert_path, leaf_certificate.public_bytes(serialization.Encoding.PEM))

    return {
        "ca_cert_path": str(ca_cert_path),
        "ca_cert_sha256": ca_certificate.fingerprint(hashes.SHA256()).hex(),
        "leaf_cert_path": str(leaf_cert_path),
        "leaf_key_path": str(leaf_key_path),
        "leaf_cert_sha256": leaf_certificate.fingerprint(hashes.SHA256()).hex(),
    }


def make_tls_context(certificates: dict[str, str]) -> ssl.SSLContext:
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.options |= ssl.OP_NO_COMPRESSION
    context.load_cert_chain(certificates["leaf_cert_path"], certificates["leaf_key_path"])

    def capture_sni(ssl_socket: ssl.SSLSocket, server_name: str | None, _: ssl.SSLContext) -> None:
        if server_name is None:
            setattr(ssl_socket, "_jm_measurement_sni", None)
            return
        try:
            setattr(ssl_socket, "_jm_measurement_sni", normalize_host(server_name))
        except ValueError:
            setattr(ssl_socket, "_jm_measurement_sni", "")

    context.set_servername_callback(capture_sni)
    return context


def read_headers(connection: socket.socket, limit: int = MAX_HEADER_BYTES) -> bytes:
    buffer = bytearray()
    while b"\r\n\r\n" not in buffer:
        chunk = connection.recv(min(4096, limit + 1 - len(buffer)))
        if not chunk:
            raise ProxyProtocolError(400, "connection closed before headers completed")
        buffer.extend(chunk)
        if len(buffer) > limit:
            raise ProxyProtocolError(431, "request headers are too large")
    header_end = buffer.index(b"\r\n\r\n") + 4
    if header_end != len(buffer):
        raise ProxyProtocolError(400, "request body or pipelined bytes are not allowed")
    return bytes(buffer)


def parse_header_block(raw: bytes) -> tuple[str, list[tuple[str, str]]]:
    try:
        text = raw.decode("iso-8859-1")
    except UnicodeDecodeError as error:
        raise ProxyProtocolError(400, "headers are not ISO-8859-1") from error
    lines = text[:-4].split("\r\n")
    if not lines or not lines[0]:
        raise ProxyProtocolError(400, "request line is missing")
    headers: list[tuple[str, str]] = []
    for line in lines[1:]:
        if not line or line[0] in " \t" or ":" not in line:
            raise ProxyProtocolError(400, "malformed request header")
        name, value = line.split(":", 1)
        if not HEADER_NAME_PATTERN.fullmatch(name):
            raise ProxyProtocolError(400, "invalid request header name")
        if "\r" in value or "\n" in value:
            raise ProxyProtocolError(400, "invalid request header value")
        headers.append((name, value.strip()))
    return lines[0], headers


def exactly_one_header(headers: list[tuple[str, str]], name: str) -> str:
    values = [value for header_name, value in headers if header_name.lower() == name.lower()]
    if len(values) != 1:
        raise ProxyProtocolError(400, f"exactly one {name} header is required")
    return values[0]


def optional_headers(headers: list[tuple[str, str]], name: str) -> list[str]:
    return [value for header_name, value in headers if header_name.lower() == name.lower()]


def path_allowed(host: str, path: str) -> bool:
    if host in API_HOSTS:
        return path in API_PATHS
    if host in CONFIG_HOSTS:
        return path == "/newsvr-2025.txt"
    if host in CDN_HOSTS:
        return path.startswith("/media/photos/") or path.startswith("/media/albums/")
    return False


def send_plain_error(connection: socket.socket, status: int, message: str) -> None:
    reason = {
        400: "Bad Request",
        403: "Forbidden",
        405: "Method Not Allowed",
        413: "Content Too Large",
        431: "Request Header Fields Too Large",
        502: "Bad Gateway",
        503: "Service Unavailable",
    }.get(status, "Proxy Error")
    body = (message + "\n").encode("utf-8")
    response = (
        f"HTTP/1.1 {status} {reason}\r\n"
        "Content-Type: text/plain; charset=utf-8\r\n"
        f"Content-Length: {len(body)}\r\n"
        "Connection: close\r\n"
        "Cache-Control: no-store\r\n\r\n"
    ).encode("ascii") + body
    try:
        connection.sendall(response)
    except OSError:
        pass


def build_upstream_request(request_line: str, headers: list[tuple[str, str]]) -> bytes:
    forwarded = [request_line]
    connection_tokens: set[str] = set()
    for value in optional_headers(headers, "Connection"):
        for token in value.split(","):
            normalized = token.strip().lower()
            if normalized:
                if not HEADER_NAME_PATTERN.fullmatch(normalized):
                    raise ProxyProtocolError(400, "Connection header contains an invalid token")
                connection_tokens.add(normalized)
    excluded = {"connection", "proxy-connection", "keep-alive"} | connection_tokens
    for name, value in headers:
        if name.lower() not in excluded:
            forwarded.append(f"{name}: {value}")
    forwarded.append("Connection: close")
    return ("\r\n".join(forwarded) + "\r\n\r\n").encode("iso-8859-1")


def normalize_upstream_response(raw: bytes) -> bytes:
    header_end = raw.find(b"\r\n\r\n")
    if header_end < 0 or not raw.startswith((b"HTTP/1.0 ", b"HTTP/1.1 ")):
        raise ProxyProtocolError(502, "fixture returned a malformed HTTP response")
    head = raw[:header_end].decode("iso-8859-1").split("\r\n")
    if not re.fullmatch(r"HTTP/1\.[01] [1-5][0-9]{2}(?: .*)?", head[0]):
        raise ProxyProtocolError(502, "fixture returned a malformed HTTP status line")
    normalized = [head[0]]
    content_lengths: list[str] = []
    transfer_encodings: list[str] = []
    for line in head[1:]:
        if not line or line[0] in " \t" or ":" not in line:
            raise ProxyProtocolError(502, "fixture returned a malformed HTTP header")
        name, value = line.split(":", 1)
        if not HEADER_NAME_PATTERN.fullmatch(name):
            raise ProxyProtocolError(502, "fixture returned an invalid HTTP header name")
        value = value.strip()
        if line.lower().startswith(("connection:", "keep-alive:")):
            continue
        if name.lower() == "content-length":
            content_lengths.append(value)
            continue
        if name.lower() == "transfer-encoding":
            transfer_encodings.append(value)
            continue
        normalized.append(f"{name}: {value}")
    if len(content_lengths) > 1:
        raise ProxyProtocolError(502, "fixture returned duplicate Content-Length headers")
    if transfer_encodings:
        raise ProxyProtocolError(502, "fixture transfer encoding is not supported by this measurement proxy")
    body_length = len(raw) - header_end - 4
    if content_lengths:
        if not content_lengths[0].isdigit() or int(content_lengths[0]) != body_length:
            raise ProxyProtocolError(502, "fixture Content-Length does not match its response body")
    normalized.append(f"Content-Length: {body_length}")
    normalized.append("Connection: close")
    return ("\r\n".join(normalized) + "\r\n\r\n").encode("iso-8859-1") + raw[header_end + 4 :]


class MeasurementProxyHandler(socketserver.BaseRequestHandler):
    server: "MeasurementProxyServer"

    def handle(self) -> None:
        try:
            self.request.settimeout(SOCKET_TIMEOUT_SECONDS)
            self._handle_connect()
        except ProxyProtocolError as error:
            send_plain_error(self.request, error.status, error.message)
        except (ConnectionError, OSError, ssl.SSLError, TimeoutError):
            return

    def _handle_connect(self) -> None:
        raw_connect = read_headers(self.request)
        request_line, headers = parse_header_block(raw_connect)
        parts = request_line.split(" ")
        if len(parts) != 3 or parts[2] not in {"HTTP/1.0", "HTTP/1.1"}:
            raise ProxyProtocolError(400, "malformed CONNECT request line")
        if parts[0] != "CONNECT":
            raise ProxyProtocolError(405, "only CONNECT is allowed")
        try:
            connect_host, connect_port = parse_authority(parts[1])
            header_host, header_port = parse_authority(exactly_one_header(headers, "Host"))
        except ValueError as error:
            raise ProxyProtocolError(400, str(error)) from error
        if connect_port != 443 or header_port != 443:
            raise ProxyProtocolError(403, "only CONNECT port 443 is allowed")
        if connect_host != header_host:
            raise ProxyProtocolError(403, "CONNECT authority and Host must match")
        if connect_host not in ALLOWED_HOSTS:
            raise ProxyProtocolError(403, "CONNECT host is not allowed")

        self.request.sendall(b"HTTP/1.1 200 Connection Established\r\nConnection: close\r\n\r\n")
        try:
            tls_connection = self.server.tls_context.wrap_socket(self.request, server_side=True)
        except ssl.SSLError:
            return
        with tls_connection:
            tls_connection.settimeout(SOCKET_TIMEOUT_SECONDS)
            sni = getattr(tls_connection, "_jm_measurement_sni", None)
            if sni not in {None, connect_host}:
                send_plain_error(tls_connection, 403, "TLS SNI and CONNECT host must match")
                return
            try:
                self._handle_http(tls_connection, connect_host)
            except ProxyProtocolError as error:
                send_plain_error(tls_connection, error.status, error.message)

    def _handle_http(self, tls_connection: ssl.SSLSocket, connect_host: str) -> None:
        raw_request = read_headers(tls_connection)
        request_line, headers = parse_header_block(raw_request)
        parts = request_line.split(" ")
        if len(parts) != 3 or parts[2] not in {"HTTP/1.0", "HTTP/1.1"}:
            raise ProxyProtocolError(400, "malformed HTTPS request line")
        method, target, _ = parts
        if method != "GET":
            raise ProxyProtocolError(405, "only GET is allowed")
        if not target.startswith("/") or target.startswith("//"):
            raise ProxyProtocolError(400, "only origin-form request targets are allowed")
        parsed_target = urlsplit(target)
        if parsed_target.scheme or parsed_target.netloc or parsed_target.fragment:
            raise ProxyProtocolError(400, "request target is invalid")
        try:
            http_host, http_port = parse_host_header(exactly_one_header(headers, "Host"))
        except ValueError as error:
            raise ProxyProtocolError(400, str(error)) from error
        if http_host != connect_host or http_port != 443:
            raise ProxyProtocolError(403, "HTTPS Host and CONNECT host must match")
        if not path_allowed(connect_host, parsed_target.path):
            raise ProxyProtocolError(403, "request path is not allowed for this host")
        if optional_headers(headers, "Transfer-Encoding"):
            raise ProxyProtocolError(400, "request transfer encoding is not allowed")
        if optional_headers(headers, "Upgrade") or optional_headers(headers, "TE") or optional_headers(headers, "Trailer"):
            raise ProxyProtocolError(400, "upgrade and trailer-related headers are not allowed")
        content_lengths = optional_headers(headers, "Content-Length")
        if len(content_lengths) > 1 or (content_lengths and content_lengths[0].strip() != "0"):
            raise ProxyProtocolError(400, "request bodies are not allowed")

        upstream_request = build_upstream_request(request_line, headers)
        try:
            with socket.create_connection(
                (self.server.config.upstream_host, self.server.config.upstream_port),
                timeout=SOCKET_TIMEOUT_SECONDS,
            ) as upstream:
                upstream.settimeout(SOCKET_TIMEOUT_SECONDS)
                upstream.sendall(upstream_request)
                response = bytearray()
                while True:
                    chunk = upstream.recv(64 * 1024)
                    if not chunk:
                        break
                    response.extend(chunk)
                    if len(response) > MAX_RESPONSE_BYTES:
                        raise ProxyProtocolError(413, "fixture response exceeds the measurement limit")
        except ProxyProtocolError:
            raise
        except (ConnectionError, OSError, TimeoutError) as error:
            raise ProxyProtocolError(502, "fixed loopback fixture is unavailable") from error
        tls_connection.sendall(normalize_upstream_response(bytes(response)))


class MeasurementProxyServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = False
    daemon_threads = True
    request_queue_size = MAX_CONCURRENT_CONNECTIONS

    def __init__(self, config: ProxyConfig, tls_context: ssl.SSLContext) -> None:
        self.config = config
        self.tls_context = tls_context
        self.connection_slots = threading.BoundedSemaphore(MAX_CONCURRENT_CONNECTIONS)
        super().__init__((config.listen_host, config.listen_port), MeasurementProxyHandler)

    def process_request(self, request: socket.socket, client_address: tuple[str, int]) -> None:
        if not self.connection_slots.acquire(blocking=False):
            send_plain_error(request, 503, "proxy connection limit reached")
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.connection_slots.release()
            raise

    def process_request_thread(self, request: socket.socket, client_address: tuple[str, int]) -> None:
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.connection_slots.release()


def atomic_write_state(path: Path, state: dict[str, object]) -> None:
    temporary = path.with_name(path.name + f".{os.getpid()}.tmp")
    content = json.dumps(state, ensure_ascii=True, sort_keys=True, indent=2).encode("utf-8") + b"\n"
    write_private_file(temporary, content)
    os.replace(temporary, path)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Loopback-only transparent HTTPS JM measurement proxy")
    parser.add_argument("--listen-host", required=True)
    parser.add_argument("--listen-port", required=True, type=int)
    parser.add_argument("--upstream-host", required=True)
    parser.add_argument("--upstream-port", required=True, type=int)
    parser.add_argument("--work-dir", required=True)
    parser.add_argument("--state-file", required=True)
    parser.add_argument("--validate-only", action="store_true")
    return parser


def main() -> int:
    parser = build_parser()
    arguments = parser.parse_args()
    try:
        config = validate_config(arguments)
        if arguments.validate_only:
            return 0
        config.work_dir.mkdir(parents=True, exist_ok=True)
        if config.state_file.exists():
            raise ValueError("state-file already exists")
        certificates = generate_ephemeral_certificates(config.work_dir)
        tls_context = make_tls_context(certificates)
        with MeasurementProxyServer(config, tls_context) as server:
            state: dict[str, object] = {
                "schema_version": 1,
                "ready": True,
                "pid": os.getpid(),
                "instance_nonce": secrets.token_hex(16),
                "listen_host": config.listen_host,
                "listen_port": config.listen_port,
                "upstream_host": config.upstream_host,
                "upstream_port": config.upstream_port,
                "allowed_hosts": list(ALLOWED_HOSTS),
                "max_concurrent_connections": MAX_CONCURRENT_CONNECTIONS,
                **certificates,
            }
            atomic_write_state(config.state_file, state)
            try:
                server.serve_forever(poll_interval=0.2)
            except KeyboardInterrupt:
                pass
        return 0
    except (ValueError, OSError) as error:
        print(f"transparent HTTPS proxy configuration error: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    signal.signal(signal.SIGINT, signal.default_int_handler)
    raise SystemExit(main())
