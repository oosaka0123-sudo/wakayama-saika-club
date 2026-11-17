(function(){
"use strict";
const dialog=document.getElementById("photo-viewer");
const cards=Array.from(document.querySelectorAll(".photo-open"));
if(!dialog||!cards.length||typeof dialog.showModal!=="function")return;
const img=document.getElementById("viewer-image");
const caption=document.getElementById("viewer-caption");
const count=document.getElementById("viewer-count");
const close=dialog.querySelector(".viewer-close");
let current=0,lastTrigger=null;
function show(index){
 current=(index+cards.length)%cards.length;
 const card=cards[current];
 const thumb=card.querySelector("img");
 img.src=thumb.getAttribute("src");
 img.alt=thumb.alt;
 caption.textContent=card.querySelector(".photo-name").textContent.trim();
 count.textContent=String(current+1).padStart(2,"0")+" / "+String(cards.length).padStart(2,"0");
}
cards.forEach((card,index)=>card.addEventListener("click",()=>{
 lastTrigger=card;
 show(index);
 dialog.showModal();
 close.focus();
}));
close.addEventListener("click",()=>dialog.close());
dialog.querySelector(".viewer-prev").addEventListener("click",()=>show(current-1));
dialog.querySelector(".viewer-next").addEventListener("click",()=>show(current+1));
dialog.addEventListener("click",e=>{if(e.target===dialog)dialog.close()});
dialog.addEventListener("keydown",e=>{
 if(e.key==="ArrowLeft"){e.preventDefault();show(current-1)}
 if(e.key==="ArrowRight"){e.preventDefault();show(current+1)}
});
dialog.addEventListener("close",()=>{img.removeAttribute("src");if(lastTrigger)lastTrigger.focus()});
})();
