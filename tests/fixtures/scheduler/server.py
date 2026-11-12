import re
from http.server import BaseHTTPRequestHandler, HTTPServer


class ProbeHandler(BaseHTTPRequestHandler):
    markers = {}

    def do_GET(self):
        status = 404
        body = b""
        if self.path == "/":
            status = 200
            body = b"ShipperCLI EasyPanel scheduler fixture"
        else:
            match = re.fullmatch(r"/(record|check)/(runner|manual|cron)/([a-f0-9]{24})", self.path)
            if match:
                operation, probe, nonce = match.groups()
                if operation == "record":
                    self.markers[probe] = nonce
                if self.markers.get(probe) == nonce:
                    status = 200
                    body = nonce.encode("ascii")

        self.send_response(status)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    HTTPServer(("0.0.0.0", 80), ProbeHandler).serve_forever()
