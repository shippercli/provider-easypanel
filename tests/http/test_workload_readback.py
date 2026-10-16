"""Public worker and scheduler apply flows over the real cURL transport."""
import http.server
import json
from pathlib import Path
import subprocess
import threading
import unittest


class WorkloadReadbackTest(unittest.TestCase):
    def test_worker_and_scheduler_writes_are_verified_before_trigger(self):
        state = {}
        traces = {}
        authorizations = []

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                parts = self.path.strip('/').split('/')
                workload, case, project, procedure = parts[0], parts[1], parts[2], parts[-1]
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))['json']
                authorizations.append(self.headers.get('Authorization'))
                if self.headers.get('Authorization') != 'synthetic-authorization-secret':
                    raise AssertionError('Synthetic authorization missing')
                trace = traces.setdefault((workload, case), [])
                trace.append(procedure + ':' + body.get('serviceName', ''))
                record = state.setdefault((workload, case), {})
                target = 'worker-default' if workload == 'worker' else 'scheduler'
                marker = 'WORKER' if workload == 'worker' else 'CRON'
                owned = f'SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={project}\nSHIPPERCLI_MANAGED_{marker}={target}'
                record.setdefault('app', f'KEEP=retained\nSHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={project}')
                record.setdefault(target, f'DB_PASSWORD=retained-secret\n{owned}')
                record.setdefault('written', set())

                if procedure == 'projects.listProjectsAndServices':
                    data = {'projects': [{'name': project}], 'services': [
                        {'projectName': project, 'name': 'web', 'type': 'app'},
                        {'projectName': project, 'name': target, 'type': 'app' if workload == 'worker' else 'box'}]}
                elif procedure.endswith('.inspectService'):
                    service = body['serviceName']
                    key = service if service == target else 'app'
                    data = {'env': record[key]}
                    if key == target and key in record['written']:
                        if case == 'missing': data = {}
                        if case == 'wrong-type': data = {'env': False}
                        if workload == 'scheduler' and case == 'saved-flat': data = {'env': record[key]}
                        elif workload == 'scheduler' and case != 'wrong-type' and case != 'missing': data = {'env': {'content': record[key]}}
                elif procedure.endswith('.updateEnv'):
                    service = body['serviceName']
                    key = service
                    incoming = body['env']
                    if isinstance(incoming, dict): incoming = incoming['content']
                    if key != target or case != 'ignored': record[key] = incoming
                    record['written'].add(key)
                    if key == target and case == 'malformed': record[key] += '\nBROKEN="unfinished'
                    if key == target and case == 'lost-retained': record[key] = '\n'.join(line for line in record[key].split('\n') if not line.startswith('SECRET='))
                    data = {}
                else:
                    data = {}
                payload = json.dumps({'json': data}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(payload)

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-workload-readback.php')), f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=30)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                for workload in ['worker', 'scheduler']:
                    for case in ['saved', 'saved-flat', 'ignored', 'malformed', 'missing', 'wrong-type', 'lost-retained']:
                        calls = traces[(workload, case)]
                        self.assertEqual(calls.count('services.' + ('app' if workload == 'worker' else 'box') + '.updateEnv:' + ('worker-default' if workload == 'worker' else 'scheduler')), 1, (workload, case, calls))
                        self.assertGreaterEqual(calls.count('services.' + ('app' if workload == 'worker' else 'box') + '.inspectService:' + ('worker-default' if workload == 'worker' else 'scheduler')), 1)
                        self.assertLess(calls.index('services.' + ('app' if workload == 'worker' else 'box') + '.updateEnv:' + ('worker-default' if workload == 'worker' else 'scheduler')), calls.index('services.' + ('app' if workload == 'worker' else 'box') + '.inspectService:' + ('worker-default' if workload == 'worker' else 'scheduler')))
                        if workload == 'worker':
                            self.assertEqual('services.app.deployService:worker-default' in calls, case in ['saved', 'saved-flat'])
                            if case not in ['saved', 'saved-flat']:
                                self.assertNotIn('services.app.updateDeploy:worker-default', calls)
                        else:
                            self.assertEqual('services.box.updateScripts:scheduler' in calls, case in ['saved', 'saved-flat'])
                            self.assertEqual('services.box.restartService:scheduler' in calls, case in ['saved', 'saved-flat'])
                self.assertTrue(authorizations)
                self.assertTrue(all(value == 'synthetic-authorization-secret' for value in authorizations))
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
