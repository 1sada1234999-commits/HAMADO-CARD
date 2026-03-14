
document.addEventListener('DOMContentLoaded', ()=>{
  const btn = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.nav');
  if(btn && nav){
    btn.addEventListener('click', ()=>{
      nav.style.display = nav.style.display === 'flex' ? 'none' : 'flex';
      nav.style.flexDirection = 'column';
      nav.style.position = 'absolute';
      nav.style.top = '84px';
      nav.style.right = '16px';
      nav.style.left = '16px';
      nav.style.background = '#0b0d10';
      nav.style.padding = '16px';
      nav.style.border = '1px solid #181d21';
      nav.style.borderRadius = '16px';
      nav.style.zIndex = '100';
    });
  }
});
