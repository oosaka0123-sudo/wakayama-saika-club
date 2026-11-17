(function () {
  "use strict";
  const form = document.getElementById("wsc-contact-form");
  const fields = document.getElementById("contact-fields");
  const status = document.getElementById("contact-status");
  if (!form || !fields || !status) return;
  const endpoint = window.WSC_FORM_ENDPOINT || "";
  // URL未設定時は送信を絶対に有効化しない（同一ページへの誤送信を防止）
  if (!/^https:\/\/formspree\.io\/f\/[A-Za-z0-9_-]{6,}$/.test(endpoint)) return;
  form.action = endpoint;
  fields.disabled = false;
  status.textContent = "お問い合わせを受け付けています。必要事項を入力して送信してください。";
  status.classList.add("ready");
  const openedAt = Date.now();
  form.addEventListener("submit", function (event) {
    // 簡易対策：自動入力や隠しフィールドへの入力を検出（本格的な対策は受付サービス側）
    const trap = form.querySelector('[name="_gotcha"]');
    if ((trap && trap.value.trim()) || Date.now() - openedAt < 3000) {
      event.preventDefault();
      status.textContent = "入力内容を確認して、数秒後にもう一度送信してください。";
      status.classList.remove("ready");
      status.focus();
    }
  });
})();
