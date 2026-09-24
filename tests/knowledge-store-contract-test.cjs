const fs = require('fs');
const path = require('path');
const assert = require('assert');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const bundle = JSON.parse(read('database/knowledge/mtpc-manual-knowledge.json'));

assert(bundle.sources.length >= 10, 'knowledge seed should contain reviewed sources');
assert(bundle.active_source_count >= 5, 'knowledge seed should contain active current sources');
assert(bundle.chunks.length === bundle.chunk_count, 'chunk count must match generated chunks');
assert(!bundle.sources.some(source => /thẻ học sinh|untitled_m2/i.test(source.file_name || '')), 'student-card sources must be excluded');
assert(!bundle.chunks.some(chunk => /IDVNM|159000089/i.test(chunk.text || '')), 'identity data must not enter RAG chunks');
assert(bundle.sources.some(source => source.title.includes('Răng Hàm Mặt') && source.active), '2026 dental notice should be active');
assert(bundle.sources.some(source => source.file_name === 'cover2.pptx' && !source.active), 'mixed historical slide deck should stay inactive');

const chat = read('api/chat56.php');
const zalo = read('api/zalo-oa.php');
const agentPage = read('index.html');
const deployment = read('.cpanel.yml');
assert(chat.includes('manual-bundle.json'), 'website chatbot must load manual knowledge');
assert(zalo.includes('manual-bundle.json'), 'Zalo Agent must load manual knowledge');
assert(zalo.includes('mtpc_zalo_agent_direct_reply'), 'Zalo Agent must route greetings and broad program-list questions deterministically');
assert(zalo.includes("MTPC_ZALO_AGENT_BUILD', 'agent-zalo-v12"), 'Zalo Agent build marker must identify the active admissions source release');
assert(zalo.includes('trường có tuyển ngành Trung cấp Y sĩ (Y sĩ đa khoa)'), 'Zalo Agent must answer the supported Y sĩ program directly');
assert(zalo.includes('mtpc_zalo_agent_verified_answer') && zalo.includes("'responseMimeType' => 'application/json'"), 'Zalo Agent must require structured Gemini evidence review');
assert(chat.includes('mtpc_verified_review') && chat.includes("'responseMimeType' => 'application/json'"), 'website Agent must require structured Gemini evidence review');
assert(zalo.includes("'reason' => 'no_verified_answer'") && zalo.includes("'sent' => false"), 'Zalo Agent must remain silent for unverified answers');
assert(zalo.includes('unanswered.jsonl') && zalo.includes("'channel' => 'zalo'"), 'Zalo Agent must queue unanswered questions for admin review');
assert(chat.includes("'silent' => true") && chat.includes("'text' => ''"), 'website Agent must signal silent abstention without fallback copy');
assert(chat.includes('unanswered.jsonl') && chat.includes('gemini_evidence_rejected'), 'website Agent must record questions rejected for insufficient evidence');
assert(agentPage.includes('if(data.silent){pending.remove();return}') && agentPage.includes("if(data.silent){setStatus('NHI // SẴN SÀNG','idle');return;}"), 'website UI must not render or speak silent abstentions');
assert(zalo.toLowerCase().includes('không tự giới thiệu lại') && zalo.toLowerCase().includes('không tự chèn số điện thoại'), 'Zalo prompt must avoid repetitive greetings and unsolicited contact details');
assert(zalo.includes('tối đa một lần trong mỗi phản hồi') && zalo.includes('tư vấn viên thật'), 'Zalo prompt must enforce a natural conversational voice');
assert(chat.toLowerCase().includes('không dùng một vài thông báo chứng chỉ mới nhất'), 'website chatbot must distinguish core programs from temporary campaigns');
assert(deployment.includes('install-knowledge-bundle.php'), 'deployment must install the reviewed bundle');
assert(deployment.includes('/home/mtpc/public_html/agent'), 'Agent deployment must target the Agent directory');
assert(!deployment.includes('/home/mtpc/public_html/admin'), 'Agent deployment must never overwrite the Dashboard');

console.log(`knowledge-store-contract: ${bundle.active_source_count}/${bundle.source_count} active sources, ${bundle.chunk_count} chunks`);
