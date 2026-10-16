"""Public provider apply through real cURL against a local tRPC fixture."""
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class EnvironmentReadbackTest(unittest.TestCase):
    def test_final_write_requires_matching_complete_readback(self):
        state = {}
        trace = {}

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                parts = self.path.strip('/').split('/')
                case, project, procedure = parts[0], parts[1], parts[-1]
                fields = json.loads(self.rfile.read(int(self.headers['Content-Length'])))['json']
                if self.headers.get('Authorization') != 'synthetic-authorization-secret':
                    raise AssertionError('Synthetic authorization missing')
                trace.setdefault(case, []).append(procedure)
                record = state.setdefault(case, {'env': 'KEEP=retained\nSHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=' + project, 'written': False})
                if procedure == 'projects.listProjectsAndServices':
                    data = {'projects': [{'name': project}], 'services': [{'projectName': project, 'name': 'app', 'type': 'app'}]}
                elif procedure == 'services.app.inspectService':
                    data = {'env': record['env']}
                    if record['written'] and case == 'missing': data = {}
                    if record['written'] and case == 'wrong-type': data = {'env': False}
                elif procedure == 'services.app.updateEnv':
                    record['written'] = True
                    if case != 'ignored': record['env'] = fields['env']
                    if case == 'malformed': record['env'] += '\nBROKEN="unfinished'
                    if case == 'lost-retained': record['env'] = record['env'].replace('KEEP=retained\n', '')
                    data = {}
                elif procedure in ['services.app.updateSourceImage', 'services.app.deployService']:
                    data = {}
                elif procedure == 'domains.listDomains':
                    data = []
                else:
                    raise AssertionError('Unexpected procedure: ' + procedure)
                payload = json.dumps({'json': data}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(payload)

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-environment-readback.php')), f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(set(trace), {'saved', 'ignored', 'malformed', 'missing', 'wrong-type', 'lost-retained'})
                for case, calls in trace.items():
                    self.assertEqual(calls.count('services.app.updateEnv'), 1)
                    self.assertEqual(calls.count('services.app.inspectService'), 2)
                    self.assertEqual('services.app.deployService' in calls, case == 'saved')
                    self.assertEqual(calls[:5], ['projects.listProjectsAndServices', 'services.app.inspectService', 'services.app.updateSourceImage', 'services.app.updateEnv', 'services.app.inspectService'])
                    if case != 'saved': self.assertEqual(len(calls), 5)
                self.assertIn('KEEP=retained', state['saved']['env'])
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
