(function(){
  'use strict';
  var status=document.getElementById('catch-status');
  var grid=document.getElementById('catch-grid');
  if(!status||!grid)return;
  var endpoint=window.WSC_CATCH_FEED_URL || '../line-api/feed.php';
  function elem(tag,className,txt){
    var e=document.createElement(tag);
    if(className)e.className=className;
    if(txt)e.textContent=txt;
    return e;
  }
  function safeImageUrl(value){
    try{var u=new URL(value,new URL(endpoint,document.baseURI));return u.protocol==='https:'||u.protocol==='http:'?u.href:null}
    catch(_){return null}
  }
  async function loadReports(){
    try{
      var res=await fetch(endpoint,{cache:'no-store',mode:'cors',headers:{'Accept':'application/json'}});
      if(!res.ok)throw new Error('HTTP '+res.status);
      var payload=await res.json();
      if(!payload||!Array.isArray(payload.posts))throw new Error('Invalid feed');
      grid.replaceChildren();
      var count=0;
      payload.posts.slice(0,30).forEach(function(post){
        var image=safeImageUrl(post.image);
        if(!image)return;
        var card=elem('article','catch-card');
        var img=document.createElement('img');
        img.src=image;
        img.alt='和歌山雑賀クラブ会員の釣果写真';
        img.loading='lazy';
        img.decoding='async';
        card.appendChild(img);
        var body=elem('div','catch-card-body');
        if(post.date){
          var t=elem('time','',new Date(post.date).toLocaleDateString('ja-JP',{year:'numeric',month:'long',day:'numeric'}));
          t.dateTime=post.date;
          body.appendChild(t);
        }
        if(post.comment)body.appendChild(elem('p','catch-comment',String(post.comment).slice(0,300)));
        if(post.author)body.appendChild(elem('p','catch-author','投稿者：'+String(post.author).slice(0,40)));
        card.appendChild(body);
        grid.appendChild(card);
        count++;
      });
      status.textContent=count?'最新'+count+'件の釣果を掲載中です。':'まだ釣果投稿はありません。';
    }catch(_){
      status.textContent='釣果速報は準備中です。LINE連携後に写真が表示されます。';
    }
  }
  loadReports();
})();