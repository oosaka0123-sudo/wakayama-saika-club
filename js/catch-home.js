(function(){
  'use strict';
  var status=document.getElementById('catch-home-status');
  var grid=document.getElementById('catch-home-grid');
  if(!status||!grid)return;
  var endpoint=window.WSC_CATCH_FEED_URL || 'line-api/feed.php';
  fetch(endpoint,{cache:'no-store',mode:'cors',headers:{'Accept':'application/json'}})
    .then(function(response){if(!response.ok)throw new Error('feed not ready');return response.json()})
    .then(function(payload){
      if(!payload||!Array.isArray(payload.posts))throw new Error('invalid feed');
      grid.replaceChildren();
      var count=0;
      payload.posts.slice(0,3).forEach(function(post){
        var url;try{url=new URL(post.image,new URL(endpoint,document.baseURI));if(!/^https?:$/.test(url.protocol))return}catch(_){return}
        var card=document.createElement('article');
        card.className='catch-card';
        var img=document.createElement('img');
        img.src=url.href;img.alt='クラブ会員の釣果写真';img.loading='lazy';img.decoding='async';
        card.appendChild(img);
        var body=document.createElement('div');body.className='catch-card-body';
        var date=document.createElement('time');date.dateTime=post.date||'';
        var parsed=new Date(post.date);date.textContent=Number.isNaN(parsed.getTime())?'':parsed.toLocaleDateString('ja-JP');
        body.appendChild(date);
        if(post.comment){var p=document.createElement('p');p.textContent=String(post.comment).slice(0,300);body.appendChild(p)}
        if(post.author){var a=document.createElement('p');a.className='catch-author';a.textContent='投稿者：'+String(post.author).slice(0,40);body.appendChild(a)}
        card.appendChild(body);grid.appendChild(card);count++;
      });
      status.textContent=count?'会員から届いた釣果を掲載しています。':'まだ釣果投稿はありません。';
    })
    .catch(function(){status.textContent='釣果速報は準備中です。LINE連携後に表示します。'});
})();