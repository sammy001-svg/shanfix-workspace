<?php
$pageTitle = 'Team Chat';
require_once __DIR__ . '/../includes/header-client.php';

$uid   = (int)$user['id'];
$orgId = (int)$user['org_id'];

// Active conversation from URL
$activeConvId = (int)($_GET['conv'] ?? 0);
?>

<style>
/* ── Chat layout ───────────────────────────────────────────────── */
.chat-wrap { display:flex; height:calc(100vh - 180px); min-height:500px; border:1px solid var(--gray-200); border-radius:12px; overflow:hidden; background:#fff; }
.chat-list  { width:280px; flex-shrink:0; border-right:1px solid var(--gray-200); display:flex; flex-direction:column; }
.chat-list-header { padding:14px 16px; border-bottom:1px solid var(--gray-200); display:flex; align-items:center; justify-content:space-between; }
.chat-list-body { flex:1; overflow-y:auto; }
.conv-item { padding:12px 16px; cursor:pointer; border-bottom:1px solid #f1f5f9; transition:background .12s; position:relative; }
.conv-item:hover { background:#f8fafc; }
.conv-item.active { background:#eff6ff; border-left:3px solid var(--green); }
.conv-item .conv-name { font-size:.85rem; font-weight:700; color:#0B2D4E; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.conv-item .conv-preview { font-size:.72rem; color:#94a3b8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.conv-unread { position:absolute; top:12px; right:12px; background:var(--green); color:white; border-radius:50%; width:18px; height:18px; font-size:.62rem; display:flex; align-items:center; justify-content:center; font-weight:700; }

.chat-main { flex:1; display:flex; flex-direction:column; min-width:0; }
.chat-main-header { padding:14px 20px; border-bottom:1px solid var(--gray-200); background:#fff; display:flex; align-items:center; gap:12px; }
.chat-messages { flex:1; overflow-y:auto; padding:16px 20px; display:flex; flex-direction:column; gap:10px; background:#f8fafc; }
.chat-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; gap:8px; }
.msg-row { display:flex; gap:10px; }
.msg-row.mine { flex-direction:row-reverse; }
.msg-avatar { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:white; flex-shrink:0; background:var(--navy); }
.msg-bubble { max-width:70%; padding:9px 13px; border-radius:16px; font-size:.85rem; line-height:1.45; word-break:break-word; }
.msg-row:not(.mine) .msg-bubble { background:#fff; border:1px solid var(--gray-200); border-bottom-left-radius:4px; color:#1e293b; }
.msg-row.mine .msg-bubble { background:var(--green); color:#fff; border-bottom-right-radius:4px; }
.msg-meta { font-size:.65rem; color:#94a3b8; margin-top:3px; }
.msg-row.mine .msg-meta { text-align:right; }

.chat-input-area { padding:12px 16px; border-top:1px solid var(--gray-200); background:#fff; display:flex; align-items:flex-end; gap:10px; }
.chat-input-area textarea { flex:1; resize:none; border:1.5px solid var(--gray-200); border-radius:20px; padding:10px 14px; font-size:.875rem; max-height:100px; transition:border .15s; }
.chat-input-area textarea:focus { outline:none; border-color:var(--green); }
.chat-send-btn { width:40px; height:40px; border-radius:50%; background:var(--green); color:white; border:none; display:flex; align-items:center; justify-content:center; flex-shrink:0; cursor:pointer; transition:.15s; }
.chat-send-btn:hover { background:#157a42; }
.chat-send-btn:disabled { opacity:.5; cursor:not-allowed; }

@media (max-width: 640px) {
  .chat-list { width: 220px; }
  .msg-bubble { max-width: 85%; }
}
</style>

<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4><i class="fas fa-comments me-2 text-green"></i>Team Chat</h4>
    <p class="text-muted mb-0 small">Instant messaging with your team members</p>
  </div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newConvModal">
    <i class="fas fa-edit me-1"></i>New Message
  </button>
</div>

<div class="chat-wrap">
  <!-- Sidebar: conversation list -->
  <div class="chat-list">
    <div class="chat-list-header">
      <span class="fw-700 text-navy small"><i class="fas fa-comments me-2 text-green"></i>Conversations</span>
      <span class="badge bg-secondary rounded-pill" id="convCount">0</span>
    </div>
    <div class="chat-list-body" id="convList">
      <div class="text-center py-4 text-muted small"><i class="fas fa-spinner fa-spin"></i></div>
    </div>
  </div>

  <!-- Main: message area -->
  <div class="chat-main">
    <div class="chat-main-header" id="chatHeader">
      <div class="chat-empty" style="flex-direction:row;background:none;padding:0">
        <i class="fas fa-comments fa-2x text-muted opacity-25"></i>
        <span class="text-muted small">Select a conversation to start chatting</span>
      </div>
    </div>
    <div class="chat-messages" id="chatMessages">
      <div class="chat-empty">
        <i class="fas fa-comments fa-3x opacity-20"></i>
        <div class="fw-600 text-muted">No conversation selected</div>
        <div class="small text-muted">Pick one from the list or start a new message</div>
      </div>
    </div>
    <div class="chat-input-area" id="chatInputArea" style="display:none">
      <textarea id="chatInput" rows="1" placeholder="Type a message… (Enter to send)" maxlength="2000"></textarea>
      <button class="chat-send-btn" id="sendBtn" onclick="sendMessage()" title="Send">
        <i class="fas fa-paper-plane" style="font-size:.85rem"></i>
      </button>
    </div>
  </div>
</div>

<!-- New Conversation Modal -->
<div class="modal fade" id="newConvModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>New Message</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Send To</label>
          <select id="recipientSelect" class="form-select">
            <option value="">Loading team members…</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Message</label>
          <textarea id="newMsgText" class="form-control" rows="3" placeholder="Write your message…" maxlength="2000"></textarea>
        </div>
        <div id="newConvErr" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="startConversation()">
          <i class="fas fa-paper-plane me-1"></i>Send
        </button>
      </div>
    </div>
  </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
// Script is inline below (avoids PHP single-quote / JS template-literal conflict)
?>
<script>
const API      = "<?= APP_URL ?>/api/chat.php";
const CURR_UID = <?= $uid ?>;
const INIT_CONV= <?= $activeConvId ?>;

let activeConvId = INIT_CONV;
let pollTimer    = null;
let lastMsgId    = 0;

/* ── Helpers ────────────────────────────────────────────────── */
function ago(ts) {
  const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
  if (diff < 60)    return "Just now";
  if (diff < 3600)  return Math.floor(diff/60) + "m ago";
  if (diff < 86400) return Math.floor(diff/3600) + "h ago";
  return new Date(ts).toLocaleDateString("en-KE", {day:"2-digit", month:"short"});
}
function initials(name) { return name.split(" ").map(w=>w[0]).join("").toUpperCase().substring(0,2); }
function avatarColor(name) {
  const c = ["#0B2D4E","#1A8A4E","#8b5cf6","#0ea5e9","#ef4444","#f59e0b","#06b6d4","#10b981"];
  let h = 0; for (let i=0;i<name.length;i++) h = name.charCodeAt(i) + ((h<<5)-h);
  return c[Math.abs(h) % c.length];
}
function escHtml(t) {
  return String(t).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

/* ── Load conversation list ──────────────────────────────────── */
function loadConversations(selectId) {
  fetch(API + "?action=list_conversations")
    .then(r=>r.json())
    .then(data=>{
      if (!data.success) return;
      const list = document.getElementById("convList");
      document.getElementById("convCount").textContent = data.conversations.length;
      if (!data.conversations.length) {
        list.innerHTML = `<div class="text-center py-4 text-muted small"><i class="fas fa-comment-slash fa-2x mb-2 d-block opacity-25"></i>No conversations yet.<br>Start one with the <strong>New Message</strong> button.</div>`;
        return;
      }
      list.innerHTML = data.conversations.map(c => {
        const active   = c.id == activeConvId ? "active" : "";
        const preview  = c.last_message ? escHtml(c.last_message).substring(0,55) + (c.last_message.length>55?"…":"") : "<em>No messages yet</em>";
        const unread   = c.unread_count > 0 ? `<span class="conv-unread">${c.unread_count}</span>` : "";
        const withWho  = escHtml(c.participants || "Unknown");
        const ts       = ago(c.updated_at);
        return `<div class="conv-item ${active}" onclick="openConv(${c.id}, '${withWho}')">
          ${unread}
          <div class="conv-name">${withWho}</div>
          <div class="conv-preview">${c.last_sender ? escHtml(c.last_sender)+": "+preview : preview}</div>
          <div style="font-size:.62rem;color:#cbd5e1;margin-top:2px">${ts}</div>
        </div>`;
      }).join("");
      if (selectId) openConv(selectId, "");
    })
    .catch(()=>{});
}

/* ── Open a conversation ─────────────────────────────────────── */
function openConv(convId, name) {
  activeConvId = convId;
  lastMsgId    = 0;
  clearTimeout(pollTimer);
  document.querySelectorAll(".conv-item").forEach(el => el.classList.remove("active"));
  const target = [...document.querySelectorAll(".conv-item")].find(el => el.getAttribute("onclick") && el.getAttribute("onclick").includes("openConv("+convId));
  if (target) target.classList.add("active");
  const header = document.getElementById("chatHeader");
  const av = name ? `<div class="msg-avatar" style="width:36px;height:36px;font-size:.8rem;background:${avatarColor(name)}">${initials(name)}</div>` : "";
  header.innerHTML = `${av}<div><div class="fw-700 text-navy" style="font-size:.9rem">${escHtml(name) || "Conversation"}</div><div class="text-muted" style="font-size:.72rem">Team chat</div></div>`;
  document.getElementById("chatInputArea").style.display = "flex";
  document.getElementById("chatMessages").innerHTML = `<div class="chat-empty"><i class="fas fa-spinner fa-spin"></i></div>`;
  fetchMessages(true);
  history.replaceState({}, "", "chat.php?conv=" + convId);
}

/* ── Fetch messages (poll) ───────────────────────────────────── */
function fetchMessages(initial) {
  if (!activeConvId) return;
  clearTimeout(pollTimer);
  fetch(API + "?action=get_messages&conv_id=" + activeConvId + "&since_id=" + lastMsgId)
    .then(r=>r.json())
    .then(data=>{
      if (!data.success) return;
      const msgs = data.messages;
      const box  = document.getElementById("chatMessages");
      const wasBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
      if (initial) box.innerHTML = "";
      if (msgs.length) {
        msgs.forEach(m => {
          const mine  = m.sender_id == CURR_UID;
          const av    = initials(m.sender_name);
          const avc   = avatarColor(m.sender_name);
          const ts    = ago(m.created_at);
          box.innerHTML += `<div class="msg-row ${mine?"mine":""}">
            ${!mine ? `<div class="msg-avatar" style="background:${avc}">${av}</div>` : ""}
            <div>
              ${!mine ? `<div style="font-size:.65rem;color:#94a3b8;margin-bottom:3px">${escHtml(m.sender_name)}</div>` : ""}
              <div class="msg-bubble">${escHtml(m.message).replace(/\n/g,"<br>")}</div>
              <div class="msg-meta">${ts}</div>
            </div>
            ${mine ? `<div class="msg-avatar" style="background:${avc}">${av}</div>` : ""}
          </div>`;
          lastMsgId = Math.max(lastMsgId, parseInt(m.id));
        });
        if (wasBottom || initial) box.scrollTop = box.scrollHeight;
      } else if (initial) {
        box.innerHTML = `<div class="chat-empty"><i class="fas fa-comment fa-2x opacity-20"></i><div class="small">No messages yet. Say hello! 👋</div></div>`;
      }
      pollTimer = setTimeout(() => fetchMessages(false), 4000);
    })
    .catch(()=>{ pollTimer = setTimeout(() => fetchMessages(false), 8000); });
}

/* ── Send message ────────────────────────────────────────────── */
function sendMessage() {
  const input = document.getElementById("chatInput");
  const msg   = input.value.trim();
  if (!msg || !activeConvId) return;
  const btn = document.getElementById("sendBtn");
  btn.disabled = true;
  const fd = new FormData();
  fd.append("action","send_message"); fd.append("conv_id",activeConvId); fd.append("message",msg);
  input.value = ""; input.style.height = "";
  fetch(API,{method:"POST",body:fd})
    .then(r=>r.json())
    .then(d=>{ if(d.success){clearTimeout(pollTimer);fetchMessages(false);} })
    .catch(()=>{})
    .finally(()=>{ btn.disabled=false; });
}

/* ── New conversation ────────────────────────────────────────── */
function startConversation() {
  const recipId = document.getElementById("recipientSelect").value;
  const msg     = document.getElementById("newMsgText").value.trim();
  const err     = document.getElementById("newConvErr");
  if (!recipId){err.textContent="Please select a recipient.";err.style.display="";return;}
  if (!msg)    {err.textContent="Please write a message.";err.style.display="";return;}
  err.style.display="none";
  const fd = new FormData();
  fd.append("action","new_conversation"); fd.append("recipient_id",recipId); fd.append("message",msg);
  fetch(API,{method:"POST",body:fd}).then(r=>r.json()).then(data=>{
    if(data.success){
      bootstrap.Modal.getInstance(document.getElementById("newConvModal")).hide();
      document.getElementById("newMsgText").value="";
      loadConversations(data.conversation_id);
    } else {err.textContent=data.message||"Failed.";err.style.display="";}
  });
}

/* ── Load users ──────────────────────────────────────────────── */
function loadUsers() {
  fetch(API+"?action=list_users").then(r=>r.json()).then(data=>{
    const sel=document.getElementById("recipientSelect");
    if(!data.success||!data.users.length){sel.innerHTML="<option>No other team members found</option>";return;}
    sel.innerHTML='<option value="">— Select a teammate —</option>'+
      data.users.map(u=>`<option value="${u.id}">${escHtml(u.name)} (${escHtml(u.role.replace(/_/g," "))})</option>`).join("");
  });
}

/* ── Textarea auto-resize + Enter to send ───────────────────── */
document.getElementById("chatInput").addEventListener("input",function(){this.style.height="";this.style.height=Math.min(this.scrollHeight,100)+"px";});
document.getElementById("chatInput").addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();sendMessage();}});
document.getElementById("newConvModal").addEventListener("show.bs.modal",loadUsers);

/* ── Boot ────────────────────────────────────────────────────── */
loadConversations(INIT_CONV > 0 ? INIT_CONV : null);
if (INIT_CONV > 0) openConv(INIT_CONV,"");
</script>
