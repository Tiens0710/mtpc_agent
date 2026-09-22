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
const deployment = read('.cpanel.yml');
assert(chat.includes('manual-bundle.json'), 'website chatbot must load manual knowledge');
assert(zalo.includes('manual-bundle.json'), 'Zalo Agent must load manual knowledge');
assert(deployment.includes('install-knowledge-bundle.php'), 'deployment must install the reviewed bundle');
assert(deployment.includes('/home/mtpc/public_html/agent'), 'Agent deployment must target the Agent directory');
assert(!deployment.includes('/home/mtpc/public_html/admin'), 'Agent deployment must never overwrite the Dashboard');

console.log(`knowledge-store-contract: ${bundle.active_source_count}/${bundle.source_count} active sources, ${bundle.chunk_count} chunks`);
