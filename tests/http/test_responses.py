"""Real cURL transport checks; all headers, request bodies and response secrets are synthetic."""
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class ResponsesTest(unittest.TestCase):
    def test_status_and_diagnostics(self):
        received = []
        redirected = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                received.append((self.path, self.headers.get('Authorization'), body))
                procedure = self.path.rsplit('/', 1)[-1]
                if self.path.startswith('/envelope/'):
                    case = self.path.split('/')[2]
                    secret = self.headers.get('Authorization', '')
                    shapes = {'missing': {}, 'array': [], 'null': None, 'number': 42, 'string': secret,
                        'error': {'error': {'json': {'message': secret}}}, 'direct-error': {'json': None, 'error': {'message': secret}},
                        'errors': {'json': None, 'errors': [secret]}, 'false': {'json': None, 'success': False},
                        'data-string': {'result': {'data': secret}}, 'nested-error': {'result': {'data': {'json': None}, 'error': secret}},
                        'ambiguous': {'json': None, 'result': {'data': {'json': None}}},
                        'valid-direct': {'json': None}, 'valid-nested': {'result': {'data': {'json': None}}}}
                    status, payload = 200, b'{"json":' if case == 'syntax' else json.dumps(shapes[case]).encode()
                elif self.path.startswith('/raw-inventory/'):
                    case, format_ = self.path.split('/')[2:4]
                    data = {'projects': [], 'services': []}
                    if case == 'projects-object': data['projects'] = {}
                    elif case == 'services-object': data['services'] = {}
                    elif case == 'numeric-object': data['projects'] = {'0': {'name': 'fixture'}}
                    elif case == 'array-record': data['projects'] = [[]]
                    envelope = {'json': data} if format_ == 'direct' else {'result': {'data': {'json': data}}}
                    status, payload = 200, json.dumps(envelope).encode()
                elif self.path.startswith('/inventory/'):
                    case, format_ = self.path.split('/')[2:4]
                    project = {'name': 'fixture'}
                    service = {'projectName': 'fixture', 'name': 'app', 'type': 'app'}
                    data = {'projects': [project], 'services': [service]}
                    if case == 'duplicate-project': data['projects'].append(project.copy())
                    elif case == 'padded-project': project['name'] = ' fixture'
                    elif case == 'duplicate-service': data['services'].append(service.copy())
                    elif case == 'conflicting-type': data['services'].append(dict(service, type='mysql'))
                    elif case == 'orphan': data['projects'] = []
                    elif case == 'padded-service': service['name'] = ' app'
                    elif case == 'padded-parent': service['projectName'] = 'fixture '
                    elif case == 'padded-type': service['type'] = 'app '
                    elif case == 'valid':
                        data['projects'].append({'name': 'second'})
                        data['services'].append(dict(service, projectName='second'))
                    envelope = {'json': data} if format_ == 'direct' else {'result': {'data': {'json': data}}}
                    status, payload = 200, json.dumps(envelope).encode()
                elif procedure.startswith('status-'):
                    _, status, format_ = procedure.split('-')
                    status = int(status)
                    echoed = self.headers.get('Authorization', '') + body.decode() + 'synthetic-error-secret'
                    payload = json.dumps({'json': {'accepted': True, 'message': echoed}}).encode() if format_ == 'json' else echoed.encode()
                elif procedure.startswith('success-'):
                    status = 200
                    payload = json.dumps({'json': {'accepted': True}} if procedure.endswith('direct') else {'result': {'data': {'json': {'accepted': True}}}}).encode()
                else:
                    redirected.append(self.path)
                    status, payload = 200, b'{}'
                self.send_response(status)
                if 300 <= status < 400:
                    self.send_header('Location', '/collect')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            do_GET = do_POST

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-responses.php')), f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(redirected, [])
                self.assertEqual(len(received), 69)
                self.assertTrue(all(auth == 'synthetic-authorization-secret' for _, auth, _ in received))
                self.assertEqual(sum(b'synthetic-body-secret' in body for _, _, body in received), 26)
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
