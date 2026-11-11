(() => {
  const config = document.getElementById('cmteh-inventory-guard');
  const input = document.getElementById('input-quantity');
  if (!config || !input) return;
  const stock = JSON.parse(config.textContent);
  input.readOnly = true;
  input.value = stock.available;
  const summary = document.createElement('p');
  summary.className = 'form-text';
  summary.textContent = `Складской учёт: на складе ${stock.on_hand}, резерв ${stock.reserved}, доступно ${stock.available}. `;
  if (stock.url) {
    const link = document.createElement('a');
    link.href = stock.url;
    link.textContent = 'Открыть склады';
    summary.append(link);
  }
  input.after(summary);
})();
