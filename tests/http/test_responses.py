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
        cleanup_states = {}

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                received.append((self.path, self.headers.get('Authorization'), body))
                procedure = self.path.rsplit('/', 1)[-1]
                if self.path.startswith('/status-safety/'):
                    case, project = self.path.split('/')[2:4]
                    if procedure == 'projects.listProjectsAndServices':
                        data = {'projects': [{'name': project}], 'services': [{'projectName': project, 'name': 'app', 'type': 'app'}]}
                    else:
                        if procedure != 'services.app.inspectService': raise AssertionError('Status must only inspect')
                        data = {'env': 'DB_PASSWORD=synthetic-status-secret', 'source': {'token': 'synthetic-status-secret'}}
                        if case == 'running': data['status'] = 'running'
                        if case == 'invalid': data['status'] = False
                        if case == 'url': data['status'] = 'https://user:synthetic-status-secret@example.test'
                    status, payload = 200, json.dumps({'json': data}).encode()
                elif self.path.startswith('/database-password/'):
                    if procedure == 'projects.listProjectsAndServices':
                        data = {'projects': [], 'services': []}
                    else:
                        request = json.loads(body)['json']
                        if procedure != 'services.mysql.createService' or request['password'] != 'literal-${PROJECT_NAME}-${PROFILE}-+%:@' or request['databaseName'] != 'main_db' or request['user'] != 'appuser':
                            raise AssertionError('Explicit password or normalized database identity changed')
                        data = {}
                    status, payload = 200, json.dumps({'json': data}).encode()
                elif self.path.startswith('/workload-delete/'):
                    kind, state = self.path.split('/')[2:4]
                    names = {'worker': 'worker-default', 'scheduler': 'scheduler', 'database': 'db-fixture'}
                    types = {'worker': 'app', 'scheduler': 'box', 'database': 'mysql'}
                    if procedure.endswith('.inspectService'):
                        data = {'env': 'SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=fixture\nSHIPPERCLI_MANAGED_WORKER=worker-default\nSHIPPERCLI_MANAGED_CRON=scheduler'}
                        if kind == 'database': data['env'] += '\nSHIPPERCLI_DATABASE_CREATED=24278f6ee6dcd2d8144ee46f'
                        if state == 'malformed': data['env'] += '\nBROKEN="unfinished'
                    elif procedure.endswith('.destroyService'): data = None
                    elif state == 'unreadable': data = None
                    else:
                        services = [] if state == 'absent' else [{'projectName': 'fixture', 'name': names[kind], 'type': 'redis' if state == 'replaced' else types[kind]}]
                        data = {'projects': [{'name': 'fixture'}], 'services': services}
                    status, payload = 200, json.dumps({'json': data}).encode()
                elif self.path.startswith('/cleanup/'):
                    case, project = self.path.split('/')[2:4]
                    state = cleanup_states.setdefault(case, {'app': False, 'project': False})
                    if procedure == 'services.app.destroyService':
                        state['app'] = True
                        data = None
                    elif procedure == 'projects.destroyProject':
                        state['project'] = True
                        data = None
                    elif procedure == 'services.app.inspectService':
                        data = {'env': 'SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=' + project}
                    else:
                        projects = [] if state['project'] and case != 'pending-project' else [{'name': project}]
                        services = [{'projectName': project, 'name': 'web', 'type': 'app'}] if not state['app'] or case == 'pending-app' else []
                        if state['app'] and case == 'other-service': services.append({'projectName': project, 'name': 'other', 'type': 'app'})
                        data = None if state['app'] and case == 'unreadable' else {'projects': projects, 'services': services}
                    status, payload = 200, json.dumps({'json': data}).encode()
                elif self.path.startswith('/database-target/'):
                    status, payload = 200, b'{"json":null}'
                elif self.path.startswith('/inspection-identity/'):
                    kind, case, format_ = self.path.split('/')[2:5]
                    data = {'projectName': 'fixture', 'name': 'target', 'serviceName': 'target', 'type': kind,
                        'env': 'SHIPPERCLI_MANAGED=1'}
                    changes = {'parent': ('projectName', 'other'), 'null-parent': ('projectName', None),
                        'name': ('name', 'other'), 'service-name': ('serviceName', 'other'),
                        'type': ('type', 'other'), 'array-name': ('name', ['synthetic-secret'])}
                    if case in changes:
                        key, value = changes[case]
                        data[key] = value
                    envelope = {'json': data} if format_ == 'direct' else {'result': {'data': {'json': data}}}
                    status, payload = 200, json.dumps(envelope).encode()
                elif self.path.startswith('/read/'):
                    kind, case, format_ = self.path.split('/')[2:5]
                    if kind == 'domains':
                        payloads = {'null': None, 'object': {}, 'string': 'synthetic-secret',
                            'array-record': [[]], 'missing-host': [{}], 'padded-host': [{'host': ' fixture.test'}],
                            'empty': [], 'valid': [{'host': 'fixture.test', 'path': '/a'}, {'host': 'fixture.test', 'path': '/b'}]}
                    else:
                        payloads = {'null': None, 'list': [{'env': 'synthetic-secret'}], 'string': 'synthetic-secret',
                            'valid': {'env': 'SHIPPERCLI_MANAGED=1'}}
                    data = payloads[case]
                    envelope = {'json': data} if format_ == 'direct' else {'result': {'data': {'json': data}}}
                    status, payload = 200, json.dumps(envelope).encode()
                elif self.path.startswith('/envelope/'):
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
                for kind in ['worker', 'scheduler', 'database']:
                    procedures = [path.rsplit('/', 1)[-1] for path, _, _ in received if path.startswith('/workload-delete/' + kind + '/malformed/')]
                    self.assertEqual(len(procedures), 1)
                    self.assertTrue(procedures[0].endswith('.inspectService'))
                self.assertEqual(len(received), 252)
                self.assertTrue(all(auth == 'synthetic-authorization-secret' for _, auth, _ in received))
                self.assertEqual(sum(b'synthetic-body-secret' in body for _, _, body in received), 26)
                for case in ['absent', 'retain-project', 'other-service', 'pending-app', 'pending-project', 'unreadable']:
                    procedures = [path.rsplit('/', 1)[-1] for path, _, _ in received if path.startswith('/cleanup/' + case + '/')]
                    self.assertEqual(procedures.count('services.app.destroyService'), 1)
                    self.assertEqual(procedures.count('projects.destroyProject'), 1 if case in ['absent', 'pending-project'] else 0)
                target_requests = [(path, json.loads(body)['json']) for path, _, body in received if path.startswith('/database-target/')]
                self.assertEqual(len(target_requests), 6)
                for path, fields in target_requests:
                    self.assertEqual(fields, {'projectName': 'fixture', 'serviceName': 'db',
                        'databaseName': 'application', 'password': 'synthetic-database-password'})
                self.assertEqual({path.rsplit('/', 1)[-1] for path, _ in target_requests}, {
                    'services.' + kind + '.createService' for kind in ['mysql', 'mariadb', 'postgres', 'mongo', 'redis']})
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
