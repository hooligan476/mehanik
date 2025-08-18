<?php require_once __DIR__.'/../middleware.php'; require_auth(); ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Мои товары</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<h2 style="padding:16px;">Мои товары</h2>
<section class="products" id="myProducts"></section>
<script src="/mehanik/assets/js/productList.js"></script>
<script>
fetch('/mehanik/api/user-products.php')
  .then(r=>r.json()).then(data=>{
    renderProducts(document.getElementById('myProducts'), data.products);
  });
</script>
</body></html>
