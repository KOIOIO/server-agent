import os
import sys
import unittest
from pathlib import Path
from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client

ROOT = Path(__file__).resolve().parents[1]

class StdioTest(unittest.IsolatedAsyncioTestCase):
    async def test_cross_server_contract(self):
        async with stdio_client(StdioServerParameters(command=sys.executable,args=['-m','demo_mcp.document_server'],cwd=str(ROOT))) as (read,write):
            async with ClientSession(read,write) as session:
                await session.initialize()
                self.assertEqual(len((await session.list_tools()).tools),2)
                reply = await session.call_tool('documents_read_case',{'case_no':'DOC-V3-001'})
                self.assertFalse(reply.isError)
                case = reply.structuredContent
                self.assertIn('North Workshop',case['document_text'])
        env = dict(os.environ)
        env.pop('DEEPSEEK_API_KEY',None)
        import tempfile
        with tempfile.TemporaryDirectory() as directory:
            env['MODEL_RESULTS_ROOT'] = directory
            async with stdio_client(StdioServerParameters(command=sys.executable,args=['-m','demo_mcp.model_server'],cwd=str(ROOT),env=env)) as (read,write):
                async with ClientSession(read,write) as session:
                    await session.initialize()
                    self.assertEqual(len((await session.list_tools()).tools),3)
                    providers = await session.call_tool('models_list_providers',{})
                    self.assertFalse(providers.isError)
                    result = await session.call_tool('models_compare_case', {k:case[k] for k in ('case_no','question','document_text')} | {'provider_ids':['model_a','model_b']})
                    self.assertFalse(result.isError)
                    self.assertEqual(result.structuredContent['results'][0]['error'],'missing_api_key')
                    saved = await session.call_tool('models_get_result',{'run_id':result.structuredContent['run_id']})
                    self.assertEqual(saved.structuredContent,result.structuredContent)
