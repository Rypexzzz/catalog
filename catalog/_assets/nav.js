(() => {
  const NAV_HTML = /*html*/`
    <nav class="catalog-nav">
      <a href="/catalog/"      class="catalog-nav__link">Главная</a>
      <a href="/catalog/list/" class="catalog-nav__link">Каталог</a>
      <a href="/catalog/basket/" class="catalog-nav__link">Корзина</a>
    </nav>`;

  const TITLE_SELECTORS = ['#pagetitle','.pagetitle','.ui-entity-section-title'];

  const markActive = () => {
    const cur = location.pathname.toLowerCase().replace(/\/+$/,'');
    document.querySelectorAll('.catalog-nav__link').forEach(a=>{
      a.classList.toggle('is-active',
        cur === a.pathname.toLowerCase().replace(/\/+$/,''));
    });
  };

  const mount = () => {
    if (document.querySelector('.catalog-nav')) return true; 
    const title = TITLE_SELECTORS
      .map(s => document.querySelector(s))
      .find(Boolean);
    if (!title) return false;

    (title.parentElement||title).insertAdjacentHTML('afterend', NAV_HTML);
    markActive();
    return true;
  };

  const boot = () => 
    mount() || new MutationObserver((_,obs)=>{
      if (mount()) obs.disconnect();
    }).observe(document.body,{childList:true,subtree:true});

  if (document.readyState!=='loading') {
    boot();
  } else {
    document.addEventListener('DOMContentLoaded', boot);
  }

  const css = `
    .catalog-nav{
      width:100%;
      margin:14px 10px 4px 0;  
      display:flex;
      gap:14px;
      flex-wrap:wrap;
    }
    .catalog-nav__link{
      padding:2px 6px;
      font:500 16px/1 'HeliosCond',Arial,sans-serif;
      color:#000;
      text-decoration:none;
      transition:color .15s;
    }
    .catalog-nav__link:hover{color:#0078c0;}
    .catalog-nav__link.is-active{
      color:#0078c0;
      border-bottom:2px solid #0078c0;
      cursor:default;
    }`;
  const s=document.createElement('style');
  s.textContent = css;
  document.head.append(s);
})();