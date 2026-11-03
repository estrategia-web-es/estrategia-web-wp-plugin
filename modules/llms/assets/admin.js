/*
 * «Generate draft» of Settings › llms.txt (modules/llms/includes/admin/class-tab.php):
 * copies the draft the server printed (the textarea without a name, #<id>-draft)
 * into the language's text, asking first if there is text. Nothing is saved
 * until «Save»: the draft only fills the field.
 */
document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-ew-llms-draft]");
  if (!button) return;
  const id = button.dataset.ewLlmsDraft;
  const text = document.getElementById(id);
  const draft = document.getElementById(id + "-draft");
  if (!text || !draft) return;
  if (text.value.trim() !== "" && !window.confirm(button.dataset.confirm)) return;
  text.value = draft.value;
  text.focus();
});
