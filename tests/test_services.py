import asyncio
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from demo_mcp.documents import Documents
from demo_mcp.models import Models

ROOT = Path(__file__).resolve().parents[1]

class DocumentsTest(unittest.TestCase):
    def test_all_nine_cases_extract(self):
        service = Documents(ROOT / 'fixtures')
        self.assertEqual(len(service.list_cases('fresh-document-analysis-9-v4.json')['cases']), 9)
        for n in range(1, 10):
            case = service.read_case('fresh-document-analysis-9-v4.json', f'DOC-V3-{n:03}')
            self.assertTrue(case['document_text'].strip())
            self.assertEqual(len(case['attachments']), 1)
        self.assertIn('North Workshop', service.read_case('fresh-document-analysis-9-v4.json', 'DOC-V3-001')['document_text'])

    def test_path_escape_rejected(self):
        with self.assertRaises(ValueError):
            Documents(ROOT / 'fixtures').list_cases('../agent-task-orchestration-proposal.md')

    def test_invalid_base64_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            Path(directory, 'bad.json').write_text(json.dumps({'cases':[{'case_no':'bad','question':'q','contents':[{'content_type':'file','content_value':'!!!','file_name':'x.txt'}]}]}))
            with self.assertRaises(ValueError):
                Documents(Path(directory)).read_case('bad.json', 'bad')

class ModelsTest(unittest.IsolatedAsyncioTestCase):
    async def test_two_models_and_persistence(self):
        import httpx
        requests = []
        def handle(request):
            requests.append(json.loads(request.content))
            self.assertEqual(request.headers['authorization'], 'Bearer secret')
            return httpx.Response(200, json={'choices':[{'message':{'content':'answer'}}], 'usage':{'total_tokens':10}})
        with tempfile.TemporaryDirectory() as directory, patch.dict('os.environ', {'TEST_KEY':'secret'}):
            service = Models({'a':{'base_url':'https://example.com/v1','model':'a','api_key_env':'TEST_KEY'},'b':{'base_url':'https://example.com/v1','model':'b','api_key_env':'TEST_KEY'}}, Path(directory), transport=httpx.MockTransport(handle))
            result = await service.compare('case-1', 'question', 'document', ['a','b'])
            self.assertEqual([r['status'] for r in result['results']], ['success','success'])
            self.assertEqual(requests[0]['messages'], requests[1]['messages'])
            self.assertEqual(service.get_result(result['run_id']), result)
            self.assertNotIn('secret', json.dumps(service.list_providers()))

    async def test_error_isolated_and_secret_hidden(self):
        import httpx
        def handle(request):
            return httpx.Response(401, text='secret')
        with tempfile.TemporaryDirectory() as directory, patch.dict('os.environ', {'TEST_KEY':'secret'}):
            service = Models({'a':{'base_url':'https://example.com/v1','model':'a','api_key_env':'TEST_KEY'}}, Path(directory), transport=httpx.MockTransport(handle))
            result = await service.compare('c','q','d',['a'])
            self.assertEqual(result['results'][0]['status'], 'error')
            self.assertNotIn('secret', json.dumps(result))
            with self.assertRaises(ValueError):
                service.get_result('../escape')
