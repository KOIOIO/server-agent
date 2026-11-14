import json
import os
from typing import Any
from pathlib import Path
from mcp.server.fastmcp import FastMCP
from demo_mcp.models import Models

root = Path(__file__).resolve().parents[1]
config_path = Path(os.getenv('MODEL_PROVIDERS_FILE', root / 'providers.example.json'))
service = Models(json.loads(config_path.read_text()), Path(os.getenv('MODEL_RESULTS_ROOT', root / 'results')))
mcp = FastMCP('models_mcp')

@mcp.tool(annotations={'readOnlyHint':True,'openWorldHint':False})
def models_list_providers() -> dict[str, Any]:
    """List configured provider IDs, models and credential readiness; never returns API keys."""
    return service.list_providers()

@mcp.tool(annotations={'readOnlyHint':False,'destructiveHint':False,'idempotentHint':False,'openWorldHint':True})
async def models_compare_case(case_no: str, question: str, document_text: str, provider_ids: list[str]) -> dict[str, Any]:
    """Send identical document/question to one or two configured providers and persist answers. Uses paid external APIs; each call creates a new run (no automatic retries). Obtain document_text from documents_read_case. Results are unreviewed, not accuracy scores."""
    return await service.compare(case_no, question, document_text, provider_ids)

@mcp.tool(annotations={'readOnlyHint':True,'openWorldHint':False})
def models_get_result(run_id: str) -> dict[str, Any]:
    """Read a saved comparison by its run_id; no new model request or charge."""
    return service.get_result(run_id)

if __name__ == '__main__':
    mcp.run(transport='stdio')
