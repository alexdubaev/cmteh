/* Optional public providers. URLs are fixed; no custom script/HTML is accepted. */
(() => {
  'use strict';
  const settings = document.currentScript.dataset;
  const load = (src) => {
    const script = document.createElement('script');
    script.async = true;
    script.src = src;
    document.head.appendChild(script);
  };
  if (/^[1-9][0-9]{0,14}$/.test(settings.yandexMetrikaId || '')) {
    const id = Number(settings.yandexMetrikaId);
    window.ym = window.ym || function () { (window.ym.a = window.ym.a || []).push(arguments); };
    window.ym.l = Date.now();
    window.ym(id, 'init', {clickmap: false, trackLinks: false, accurateTrackBounce: true, webvisor: false, trackForms: false});
    load('https://mc.yandex.ru/metrika/tag.js');
  }
  if (/^G-[A-Z0-9]{1,32}$/.test(settings.googleAnalyticsId || '')) {
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', settings.googleAnalyticsId);
    load('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(settings.googleAnalyticsId));
  }
})();
