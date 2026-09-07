document.addEventListener('change', event => {
  if (event.target.matches('[data-followup-escalation]')) {
    event.target.closest('form').querySelector('[data-followup-escalation-date]').hidden = event.target.value === '0';
  }
});
function showTicketFollowups() {
  if (location.hash === '#followups') document.querySelector('#followups > details')?.setAttribute('open', '');
}
window.addEventListener('hashchange', showTicketFollowups);
showTicketFollowups();
