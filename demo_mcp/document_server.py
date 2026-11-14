import os
from typing import Any
from pathlib import Path
from mcp.server.fastmcp import FastMCP
from demo_mcp.documents import Documents

mcp = FastMCP('documents_mcp')
service = Documents(Path(os.getenv('DOCUMENT_ROOT', Path(__file__).resolve().parents[1] / 'fixtures')))
READ = {'readOnlyHint': True, 'destructiveHint': False, 'idempotentHint': True, 'openWorldHint': False}

@mcp.tool(annotations=READ)
def documents_list_cases(batch_file: str = 'fresh-document-analysis-9-v4.json') -> dict[str, Any]:
    """List case IDs/questions without Base64. batch_file is relative to DOCUMENT_ROOT."""
    return service.list_cases(batch_file)

@mcp.tool(annotations=READ)
def documents_read_case(case_no: str, batch_file: str = 'fresh-document-analysis-9-v4.json') -> dict[str, Any]:
    """Decode attachments and return question, document_text, hashes and warnings. Pass question/document_text to models_compare_case on the other MCP server. No OCR or binary DOC support."""
    return service.read_case(batch_file, case_no)

if __name__ == '__main__':
    mcp.run(transport='stdio')
