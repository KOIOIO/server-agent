"""Read-only, bounded document batch access; never writes user filenames."""
import base64
import binascii
import hashlib
import io
import json
import zipfile
from pathlib import Path
from xml.etree import ElementTree

MAX_FILE = 10 * 1024 * 1024
MAX_TEXT = 200_000

class Documents:
    def __init__(self, root: Path):
        self.root = root.resolve()

    def _load(self, batch_file):
        path = (self.root / batch_file).resolve()
        if not path.is_relative_to(self.root) or not path.is_file() or path.suffix != '.json':
            raise ValueError('Choose a JSON batch file inside DOCUMENT_ROOT.')
        if path.stat().st_size > 25 * 1024 * 1024:
            raise ValueError('Batch exceeds 25 MiB limit.')
        data = json.loads(path.read_text())
        cases = data.get('cases')
        if not isinstance(cases, list) or len(cases) > 500:
            raise ValueError('Batch requires a cases array with at most 500 cases.')
        ids = [c.get('case_no') for c in cases]
        if any(not isinstance(x, str) or not x for x in ids) or len(set(ids)) != len(ids):
            raise ValueError('case_no must be unique nonempty strings.')
        return data

    def list_cases(self, batch_file: str):
        data = self._load(batch_file)
        return {'batch_no': data.get('batch_no'), 'service_type': data.get('service_type'), 'cases': [
            {k: c.get(k) for k in ('case_no', 'sort_no', 'description', 'question')} for c in data['cases']]}

    def read_case(self, batch_file: str, case_no: str):
        data = self._load(batch_file)
        case = next((c for c in data['cases'] if c['case_no'] == case_no), None)
        if case is None:
            raise ValueError('Unknown case_no; call documents_list_cases first.')
        if not isinstance(case.get('question'), str) or not case['question'].strip():
            raise ValueError('Case requires a nonempty question.')
        texts, attachments, warnings = [], [], []
        for item in case.get('contents', []):
            if item.get('direction', 'input') != 'input' or item.get('content_type') != 'file':
                continue
            encoded = item.get('content_value', '')
            if not isinstance(encoded, str) or len(encoded) > MAX_FILE * 4 // 3 + 4:
                raise ValueError('Attachment exceeds 10 MiB limit.')
            try:
                raw = base64.b64decode(encoded, validate=True)
            except (ValueError, binascii.Error) as exc:
                raise ValueError('Invalid attachment Base64.') from exc
            if len(raw) > MAX_FILE:
                raise ValueError('Attachment exceeds 10 MiB limit.')
            name = item.get('file_name', 'attachment')
            if item.get('size_bytes') != len(raw):
                warnings.append(f'{name}: declared size differs from decoded size ({len(raw)}).')
            if raw.startswith(b'{\\rtf'):
                from striprtf.striprtf import rtf_to_text
                text, actual = rtf_to_text(raw.decode('latin1')), 'rtf'
            elif raw.startswith(b'PK'):
                with zipfile.ZipFile(io.BytesIO(raw)) as archive:
                    info = archive.getinfo('word/document.xml')
                    if info.file_size > MAX_FILE:
                        raise ValueError('Expanded DOCX XML exceeds 10 MiB.')
                    xml = ElementTree.fromstring(archive.read(info))
                    ns = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
                    text = '\n'.join(''.join(p.itertext()) for p in xml.iter(ns + 'p'))
                actual = 'docx'
            elif raw.startswith(b'%PDF-'):
                from pypdf import PdfReader
                reader = PdfReader(io.BytesIO(raw))
                if len(reader.pages) > 100:
                    raise ValueError('PDF exceeds 100 pages.')
                text, actual = '\n'.join(p.extract_text() or '' for p in reader.pages), 'pdf'
            elif Path(name).suffix.lower() == '.txt':
                text, actual = raw.decode('utf-8-sig'), 'txt'
            else:
                raise ValueError('Unsupported attachment: use UTF-8 TXT, RTF, DOCX or text PDF. Binary DOC requires conversion.')
            if actual == 'rtf' and Path(name).suffix.lower() == '.doc':
                warnings.append(f'{name}: .doc contains RTF, not binary Word DOC.')
            if not text.strip():
                raise ValueError('No extractable text; OCR is not supported in this Demo.')
            texts.append(text)
            attachments.append({'file_name': name, 'format': actual, 'mime_type': item.get('mime_type'), 'decoded_size': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()})
        document = '\n\n'.join(texts)
        if not document or len(document) > MAX_TEXT:
            raise ValueError('Case must contain extractable documents with at most 200000 characters; no silent truncation.')
        return {'batch_no': data.get('batch_no'), 'case_no': case_no, 'question': case['question'], 'document_text': document, 'attachments': attachments, 'warnings': warnings}
