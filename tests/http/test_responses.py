"""Real Guzzle transport checks; all headers, request bodies and response secrets are synthetic."""
import hashlib
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
        deletion_methods = []
        pending = [False]
        deleted_sites = set()
        environments = {}
        partial_databases = []
        partial_events = []
        partial_attempts = [0]

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
                received.append((self.path, self.headers.get('Authorization'), body))
                procedure = self.path.rsplit('/', 1)[-1]
                if self.path == '/servers/127/sites/55':
                    case = self.headers['X-Fixture-Case'].removeprefix('poll-')
                    record = {'id': 55, 'server_id': 127, 'status': 'active'}
                    envelope = {'data': record}
                    if case == 'deploying': record['status'] = 'deploying'
                    if case == 'failed': record['status'] = 'deploy-failed'
                    if case == 'wrong-id': record['id'] = 56
                    if case == 'wrong-server': record['server_id'] = 128
                    if case == 'missing-status': record.pop('status')
                    if case == 'false': envelope['success'] = False
                    if case == 'invalid-flag': record['deploying'] = 0
                    status, payload = 200, json.dumps(envelope).encode()
                elif self.path == '/servers/126/sites/55/env':
                    case = self.headers['X-Fixture-Case']
                    marker = hashlib.sha256(b'["fixture","production"]').hexdigest()
                    envelope = {'data': {'content': 'SHIPPERCLI_OWNERSHIP=' + marker}}
                    if case == 'ownership-false': envelope['success'] = False
                    if case == 'ownership-errors': envelope['errors'] = ['synthetic-secret']
                    if case == 'ownership-error': envelope['error'] = 'synthetic-secret'
                    status, payload = 200, json.dumps(envelope).encode()
                elif self.path == '/servers/125/sites/55/env':
                    if self.command == 'PATCH':
                        environments['partial'] = json.loads(body)['content']
                        partial_events.append('deliver')
                    status, payload = 200, json.dumps({'data': {'content': environments.get('partial', 'APP_ENV=production\n')}}).encode()
                elif self.path.split('?')[0] == '/servers/125/databases':
                    status = 200
                    if self.command == 'GET':
                        envelope = {'data': partial_databases, 'meta': {'last_page': 1}}
                    else:
                        request = json.loads(body)
                        name = request['name']
                        partial_events.append('create:' + name)
                        if name == 'analytics':
                            partial_attempts[0] += 1
                        if name == 'analytics' and partial_attempts[0] == 1:
                            status, envelope = 500, {'message': 'Synthetic creation failure'}
                        else:
                            record = {'id': len(partial_databases) + 1, 'name': name, 'site': {'id': 55}}
                            partial_databases.append(record)
                            envelope = {'data': record}
                    payload = json.dumps(envelope).encode()
                elif self.path == '/servers/124/sites/55/env':
                    case = self.headers['X-Fixture-Case']
                    if self.command == 'PATCH' and case != 'final-ignored':
                        environments[case] = json.loads(body)['content']
                    status, payload = 200, json.dumps({'data': {'content': environments.get(case, 'APP_ENV=production\n')}}).encode()
                elif self.path.split('?')[0] == '/servers/124/databases':
                    case = self.headers['X-Fixture-Case']
                    if self.command == 'GET':
                        envelope = {'data': [], 'meta': {'last_page': 1}}
                    else:
                        request = json.loads(body)
                        if request.get('name') != 'fixture' or request.get('user') != 'fixture_user' or request.get('site_id') != 55 or not request.get('password'):
                            raise AssertionError('Unexpected database creation request')
                        if case == 'valid-explicit' and request['password'] != 'literal-${PROJECT_NAME}-${PROFILE}-+%:@':
                            raise AssertionError('Explicit database password changed in HTTP payload')
                        record = {'id': 9, 'name': 'fixture'}
                        if case == 'wrong-name': record['name'] = 'foreign'
                        if case == 'bad-id': record['id'] = '9junk'
                        if case == 'wrong-site': record['site'] = {'id': 56}
                        if case == 'valid-site': record['site'] = {'id': '55'}
                        envelope = {'data': record}
                        if case == 'missing': envelope = {}
                        if case == 'false': envelope['success'] = False
                        if case == 'error': envelope['errors'] = [self.headers.get('Authorization', '')]
                    status, payload = 200, json.dumps(envelope).encode()
                elif self.path == '/servers/7':
                    deletion_methods.append(self.command)
                    if self.command == 'DELETE':
                        pending[0] = True
                        status, payload = 200, b'{"data":{"id":7,"status":"destroying"}}'
                    elif pending[0]:
                        status, payload = 404, b'{}'
                    else:
                        status, payload = 200, b'{"data":{"id":7,"status":"active"}}'
                elif self.path.split('?')[0].startswith('/servers/123/sites'):
                    case = self.headers['X-Fixture-Case']
                    if self.command == 'DELETE':
                        deleted_sites.add(case)
                        envelope = {'error': {'message': 'Deleted', 'errors': [self.headers.get('Authorization', '')]},
                            'false': {'message': 'Deleted', 'success': False}, 'missing-message': {},
                            'failed-message': {'message': 'failed ' + self.headers.get('Authorization', '')}}.get(case, {'message': 'Site has been deleted'})
                    else:
                        if case not in deleted_sites:
                            data = [] if case == 'already-absent' else [{'id': 55, 'domain': 'fixture.test'}]
                        else:
                            data = {'visible': [{'id': 55, 'domain': 'fixture.test'}],
                                'recreated': [{'id': 56, 'domain': 'FIXTURE.test'}],
                                'renamed': [{'id': 55, 'domain': 'other.test'}]}.get(case, [])
                        envelope = {} if case == 'unreadable' and case in deleted_sites else {'data': data, 'meta': {'last_page': 1}}
                    status, payload = 200, json.dumps(envelope).encode()
                elif self.path.split('?')[0].startswith('/servers/123/databases'):
                    case = self.headers['X-Fixture-Case']
                    if self.command == 'DELETE':
                        status, payload = 200, (json.dumps({'errors': [self.headers.get('Authorization', '')]}).encode() if case == 'error' else b'')
                    else:
                        data = {'visible': [{'id': 9, 'name': 'fixture'}],
                                'recreated': [{'id': 10, 'name': 'fixture'}],
                                'renamed': [{'id': 9, 'name': 'other'}]}.get(case, [])
                        envelope = {} if case == 'unreadable' else {'data': data, 'meta': {'last_page': 1}}
                        status, payload = 200, json.dumps(envelope).encode()
                elif self.path.split('?')[0] == '/servers':
                    case = self.headers['X-Fixture-Case']
                    echoed = self.headers.get('Authorization', '') + 'synthetic-error-secret'
                    envelope = {'data': {'id': 123}} if self.command == 'POST' else {
                        'data': [{'id': 123, 'name': 'fixture'}], 'meta': {'last_page': 1}}
                    envelope.update({'error': {'error': echoed}, 'errors': {'errors': [echoed]},
                        'false': {'success': False}, 'string': {'success': 'true'},
                        'null': {'success': None}, 'valid': {'success': True, 'error': None, 'errors': []}}[case])
                    status, payload = 200, json.dumps(envelope).encode()
                elif procedure.startswith('json-shape-'):
                    case = procedure.removeprefix('json-shape-')
                    status = 204 if case == 'empty204' else 200
                    payload = {'null': b'null', 'number': b'42', 'boolean': b'true',
                        'string': json.dumps(self.headers.get('Authorization', '')).encode(),
                        'array': b'[]', 'records': b'[{"id":1}]', 'syntax': b'{"error":',
                        'utf8': b'{"error":"' + bytes([255]) + b'"}',
                        'object': b'{"data":{"accepted":true}}', 'empty': b'', 'empty204': b''}[case]
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
            do_DELETE = do_POST
            do_PATCH = do_POST

        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                result = subprocess.run(['php', str(Path(__file__).with_name('verify-responses.php')), f'http://127.0.0.1:{server.server_port}'], capture_output=True, text=True, timeout=15)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(partial_events, ['create:main', 'deliver', 'create:analytics', 'create:analytics', 'deliver'])
                self.assertEqual(redirected, [])
                self.assertEqual(deletion_methods, ['GET', 'DELETE', 'GET'])
                self.assertEqual(len(received), 210)
                self.assertTrue(all(auth == 'Bearer synthetic-authorization-secret' for _, auth, _ in received))
                self.assertEqual(sum(b'synthetic-body-secret' in body for _, _, body in received), 30)
            finally:
                server.shutdown()
                thread.join(timeout=2)


if __name__ == '__main__':
    unittest.main()
