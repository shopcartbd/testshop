<?php
declare(strict_types=1);
/**
 * Idempotent seed data. No admin account and no usable password is created
 * here: the first administrator is provisioned by scripts/bootstrap_admin.php
 * using server-side environment variables only.
 * Usage: php database/seed.php [--force]
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use AllStore\Core\Database;
use AllStore\Core\Helpers;
use AllStore\Core\Config;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
if (!function_exists('allstore_seed_output')) {
    function allstore_seed_output(string $line): void
    {
        fwrite(STDOUT, $line);
    }
}
$force = in_array('--force', $argv ?? [], true);
if (!$force && Database::value("SELECT value FROM settings WHERE key='seeded_v1'") === '1') {
    allstore_seed_output("Already seeded. Use --force to reseed.\n");
    exit(0);
}
if ($force) {
    // Development helper: wipe application tables but keep the schema.
    foreach (Database::all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) {
        try { Database::pdo()->exec('DELETE FROM ' . $t['name']); } catch (\Throwable) {}
    }
}
$now = Helpers::now();
if (!function_exists('allstore_seed_uuid')) {
    function allstore_seed_uuid(): string { return Helpers::uuid(); }
}
if (!function_exists('allstore_seed_row')) {
    function allstore_seed_row(string $table, array $d): void { Database::insert($table, $d); }
}

/* ------------------------------------------------------------------ RBAC */
$roles = ['super_admin'=>'Super Administrator','admin'=>'Administrator','staff'=>'Store Staff','customer'=>'Customer'];
$roleIds = [];
foreach ($roles as $slug => $name) { $roleIds[$slug] = allstore_seed_uuid(); allstore_seed_row('roles', ['id'=>$roleIds[$slug],'name'=>$name,'slug'=>$slug,'description'=>'Seeded role','is_system'=>1,'created_at'=>$now]); }
$perms = ['dashboard.view','products.view','products.manage','categories.manage','brands.manage','attributes.manage',
    'inventory.manage','orders.view','orders.manage','customers.view','customers.manage','coupons.manage',
    'promotions.manage','cms.manage','reviews.moderate','reports.view','settings.manage','security.manage','tickets.manage','users.manage'];
$permIds = [];
foreach ($perms as $p) { $permIds[$p] = allstore_seed_uuid(); allstore_seed_row('permissions', ['id'=>$permIds[$p],'name'=>ucwords(str_replace('.',' ',$p)),'slug'=>$p,'module'=>explode('.',$p)[0],'created_at'=>$now]); }
foreach (['super_admin','admin'] as $r) { foreach ($permIds as $pid) { allstore_seed_row('role_permissions', ['role_id'=>$roleIds[$r],'permission_id'=>$pid]); } }
foreach (array_slice($perms,0,9) as $p) { allstore_seed_row('role_permissions', ['role_id'=>$roleIds['staff'],'permission_id'=>$permIds[$p]]); }

/* --------------------------------------------------------------- settings */
$settings = [
    'store_name'=>(string)Config::get('app.name'), 'store_tagline'=>'Everything you need, thoughtfully curated.',
    'announcement_text'=>'Complimentary shipping on orders over $75  •  New arrivals every week',
    'announcement_enabled'=>'1', 'contact_email'=>'hello@allstore.example', 'contact_phone'=>'+1 555 010 2040',
    'store_address'=>'24 Market Street, Suite 200, San Francisco, CA', 'default_country'=>'US',
    'default_shipping_cents'=>'799', 'cod_enabled'=>'1', 'free_shipping_threshold_cents'=>'7500',
    'currency'=>(string)Config::get('app.currency'), 'footer_about'=>'A modern marketplace for considered everyday goods.',
    'social_instagram'=>'https://instagram.com', 'social_x'=>'https://x.com', 'social_facebook'=>'https://facebook.com',
    'newsletter_heading'=>'Stay in the loop', 'newsletter_text'=>'New arrivals, editorial picks and member-only offers. No noise.',
    'maintenance_mode'=>'0', 'seeded_v1'=>'1', 'bootstrap_completed'=>'0',
];
foreach ($settings as $k=>$v) { allstore_seed_row('settings', ['key'=>$k,'value'=>$v,'group_name'=>'general','updated_at'=>$now]); }

/* -------------------------------------------------------------- catalogue */
$categories = [
    'Electronics'=>['icon'=>'cpu','children'=>['Mobile Phones','Computers & Laptops','Audio & Headphones','Cameras']],
    'Fashion & Clothing'=>['icon'=>'shirt','children'=>['Shoes','Bags & Accessories','Watches','Jewelry']],
    'Home & Living'=>['icon'=>'home','children'=>['Furniture','Kitchen & Dining','Office Products']],
    'Beauty & Personal Care'=>['icon'=>'sparkles','children'=>[]],
    'Sports & Fitness'=>['icon'=>'dumbbell','children'=>[]],
    'Books & Stationery'=>['icon'=>'book','children'=>[]],
    'Grocery & Food'=>['icon'=>'coffee','children'=>[]],
    'Automotive Accessories'=>['icon'=>'car','children'=>[]],
    'Pet Supplies'=>['icon'=>'paw','children'=>[]],
    'Travel & Outdoor'=>['icon'=>'compass','children'=>[]],
    'Toys & Games'=>['icon'=>'game','children'=>[]],
    'Other'=>['icon'=>'grid','children'=>[]],
];
$catIds = []; $sort = 0;
foreach ($categories as $name => $meta) {
    $id = allstore_seed_uuid(); $catIds[$name] = $id;
    allstore_seed_row('categories', ['id'=>$id,'parent_id'=>null,'name'=>$name,'slug'=>Helpers::slugify($name),
        'description'=>'Explore our curated ' . strtolower($name) . ' collection.',
        'image_url'=>'/assets/img/products/laptop.jpg','icon'=>$meta['icon'],'banner_url'=>'/assets/img/promo-wide.jpg',
        'seo_title'=>$name.' | AllStore','seo_description'=>'Shop '.$name.' at AllStore.',
        'sort_order'=>$sort++,'is_active'=>1,'is_featured'=>$sort <= 8 ? 1 : 0,'created_at'=>$now,'updated_at'=>$now]);
    foreach ($meta['children'] as $child) {
        $cid = allstore_seed_uuid(); $catIds[$child] = $cid;
        allstore_seed_row('categories', ['id'=>$cid,'parent_id'=>$id,'name'=>$child,'slug'=>Helpers::slugify($child),
            'description'=>'Discover ' . strtolower($child) . '.','image_url'=>'/assets/img/products/headphones.jpg',
            'icon'=>$meta['icon'],'banner_url'=>'','seo_title'=>$child.' | AllStore','seo_description'=>'Shop '.$child,
            'sort_order'=>0,'is_active'=>1,'is_featured'=>0,'created_at'=>$now,'updated_at'=>$now]);
    }
}
$brandNames = ['Aurora','Nord','Kanto','Meridian','Lumen','Terra','Vela','Atlas'];
$brandIds = []; $i = 0;
foreach ($brandNames as $b) {
    $brandIds[$b] = allstore_seed_uuid();
    allstore_seed_row('brands', ['id'=>$brandIds[$b],'name'=>$b,'slug'=>Helpers::slugify($b),'logo_url'=>'',
        'description'=>$b.' designs considered objects for everyday life.','website'=>'',
        'is_active'=>1,'sort_order'=>$i++,'created_at'=>$now,'updated_at'=>$now]);
}
$attributeDefs = [
    'Color'=>['type'=>'color','values'=>['charcoal'=>'Charcoal','ivory'=>'Ivory','sand'=>'Sand','olive'=>'Olive','silver'=>'Silver','tan'=>'Tan']],
    'Size'=>['type'=>'dropdown','values'=>['xs'=>'XS','s'=>'S','m'=>'M','l'=>'L','xl'=>'XL','42'=>'42']],
    'Material'=>['type'=>'dropdown','values'=>['leather'=>'Leather','cotton'=>'Cotton','aluminium'=>'Aluminium','oak'=>'Oak','glass'=>'Glass']],
    'Storage'=>['type'=>'dropdown','values'=>['128gb'=>'128 GB','256gb'=>'256 GB','512gb'=>'512 GB']],
    'RAM'=>['type'=>'dropdown','values'=>['8gb'=>'8 GB','16gb'=>'16 GB','32gb'=>'32 GB']],
    'Screen Size'=>['type'=>'measurement','unit'=>'inch','values'=>['6.1'=>'6.1 in','6.7'=>'6.7 in','14'=>'14 in']],
];
$attrIds = []; $valueIds = []; $ai = 0;
foreach ($attributeDefs as $name => $def) {
    $id = allstore_seed_uuid(); $attrIds[$name] = $id;
    allstore_seed_row('attributes', ['id'=>$id,'name'=>$name,'slug'=>Helpers::slugify($name),'type'=>$def['type'],
        'unit'=>$def['unit'] ?? '', 'is_filterable'=>1,'sort_order'=>$ai++,'created_at'=>$now]);
    foreach ($def['values'] as $v=>$label) {
        $vid = allstore_seed_uuid(); $valueIds[$name.':'.$v] = $vid;
        allstore_seed_row('attribute_values', ['id'=>$vid,'attribute_id'=>$id,'value'=>$v,'label'=>$label,
            'color_hex'=>in_array($v,['charcoal','ivory','sand','olive','silver','tan'],true) ? '#9a9186' : null,
            'sort_order'=>0]);
    }
}
foreach (['Electronics','Fashion & Clothing','Home & Living'] as $c) {
    foreach (['Color','Size','Material','Storage','RAM'] as $a) {
        allstore_seed_row('category_attributes', ['category_id'=>$catIds[$c],'attribute_id'=>$attrIds[$a],'is_filterable'=>1,'sort_order'=>0]);
    }
}
$warehouseId = allstore_seed_uuid();
allstore_seed_row('warehouses', ['id'=>$warehouseId,'name'=>'Main Warehouse','code'=>'MAIN','address'=>'Central fulfilment','is_default'=>1,'is_active'=>1,'created_at'=>$now]);

$img = [
    'headphones'=>'/assets/img/products/headphones.jpg','phone'=>'/assets/img/products/smartphone.jpg',
    'laptop'=>'/assets/img/products/laptop.jpg','shoes'=>'/assets/img/products/sneakers.jpg',
    'watch'=>'/assets/img/products/watch.jpg','chair'=>'/assets/img/products/chair.jpg',
    'beauty'=>'/assets/img/products/skincare.jpg','coffee'=>'/assets/img/products/coffee.jpg',
];
$products = [
    ['Aurora Studio Wireless Headphones','headphones','Electronics','Aurora',24900,32900,48,1,1,0,1,'Over-ear headphones with balanced sound, memory-foam cushions and a 40-hour battery.'],
    ['Nord Titanium Smartphone 256GB','phone','Mobile Phones','Nord',89900,99900,32,1,1,1,1,'A refined 6.1-inch smartphone with an aerospace-grade frame and all-day battery.'],
    ['Kanto AirBook 14 Laptop','laptop','Computers & Laptops','Kanto',129900,149900,18,1,1,1,0,'A 14-inch ultraportable laptop built for focused work, with a crisp matte display.'],
    ['Vela Everyday Leather Sneakers','shoes','Shoes','Vela',12900,15900,64,1,1,0,1,'Minimal low-top sneakers in supple full-grain leather with a cushioned footbed.'],
    ['Meridian Field Watch 38mm','watch','Watches','Meridian',21900,26900,27,1,0,0,0,'A quiet, precise three-hand watch with a sapphire crystal and Italian leather strap.'],
    ['Terra Oak Lounge Chair','chair','Furniture','Terra',47900,59900,12,1,0,1,0,'Solid oak frame with boucle upholstery. Designed for long, comfortable evenings.'],
    ['Lumen Daily Skincare Duo','beauty','Beauty & Personal Care','Lumen',4900,6400,120,1,1,0,1,'A gentle cleanser and barrier-supporting moisturiser for everyday routines.'],
    ['Atlas Pour-Over Coffee Set','coffee','Kitchen & Dining','Atlas',7900,9900,55,1,0,0,1,'Everything for a considered morning: dripper, glass carafe and ceramic cup.'],
    ['Aurora Buds Pro','headphones','Audio & Headphones','Aurora',14900,18900,88,0,1,1,1,'Compact wireless earbuds with adaptive noise control and a pocketable case.'],
    ['Nord Compact Phone 128GB','phone','Mobile Phones','Nord',64900,72900,41,0,0,1,0,'All the essentials in a smaller, lighter form factor with a bright OLED screen.'],
    ['Kanto Workstation 16','laptop','Computers & Laptops','Kanto',179900,199900,9,1,0,0,0,'A powerful 16-inch workstation for creative professionals.'],
    ['Vela Canvas Weekender','shoes','Bags & Accessories','Vela',18900,22900,36,0,1,0,1,'A structured weekender in waxed canvas with vegetable-tanned leather trim.'],
    ['Meridian Chrono Steel','watch','Watches','Meridian',38900,44900,14,0,0,1,0,'Brushed steel chronograph with a clean dial and reliable automatic movement.'],
    ['Terra Dining Table','chair','Furniture','Terra',89900,109900,6,0,0,0,0,'Solid oak dining table with a soft matte finish, seats six comfortably.'],
    ['Lumen Hand Balm','beauty','Beauty & Personal Care','Lumen',2400,3200,210,0,1,0,0,'A rich, fast-absorbing balm with shea butter and a subtle cedar scent.'],
    ['Atlas Ceramic Cup Set','coffee','Kitchen & Dining','Atlas',3900,4900,140,0,0,1,0,'Four hand-finished stoneware cups with a comfortable, balanced weight.'],
    ['Aurora Desk Speaker','headphones','Audio & Headphones','Aurora',19900,24900,29,1,0,0,0,'Room-filling sound from a compact, fabric-wrapped desktop speaker.'],
    ['Nord Tablet 11','phone','Electronics','Nord',54900,62900,23,0,1,1,1,'An 11-inch tablet for reading, sketching and everything in between.'],
    ['Kanto Mechanical Keyboard','laptop','Office Products','Kanto',12900,15900,74,0,0,0,1,'Low-profile mechanical keyboard with a warm white backlight.'],
    ['Vela Runner Knit','shoes','Shoes','Vela',10900,13900,58,0,0,1,0,'Breathable knit runners with a responsive foam sole.'],
    ['Terra Floor Lamp','chair','Home & Living','Terra',16900,20900,19,0,0,0,1,'A slim floor lamp with a linen shade and dimmable warm light.'],
    ['Atlas Travel Tumbler','coffee','Travel & Outdoor','Atlas',3400,4200,160,0,1,0,0,'Double-walled stainless tumbler that keeps drinks warm for eight hours.'],
    ['Lumen Sunscreen SPF 40','beauty','Beauty & Personal Care','Lumen',3200,3900,95,0,0,1,1,'Lightweight daily sunscreen with no white cast.'],
    ['Meridian Leather Card Holder','watch','Bags & Accessories','Meridian',5900,7400,82,0,0,0,0,'Slim card holder in vegetable-tanned leather, holds six cards.'],
];
$productIds = []; $pIndex = 0;
foreach ($products as $p) {
    [$name,$image,$cat,$brand,$price,$compare,$stock,$featured,$best,$new,$trend,$short] = $p;
    $id = allstore_seed_uuid(); $productIds[] = $id;
    $slug = Helpers::slugify($name);
    allstore_seed_row('products', [
        'id'=>$id,'name'=>$name,'slug'=>$slug,'sku'=>'AS-'.str_pad((string)($pIndex+1),4,'0',STR_PAD_LEFT),
        'barcode'=>'0'.str_pad((string)(880000000000+$pIndex),12,'0',STR_PAD_LEFT),
        'category_id'=>$catIds[$cat],'brand_id'=>$brandIds[$brand],
        'short_description'=>$short,'full_description'=>$short."\n\nDesigned with attention to materials, proportion and the small details that make an object a pleasure to use. Every piece is checked before it leaves our warehouse and is backed by our returns promise.",
        'product_type'=>'simple','cost_cents'=>(int)round($price*0.58),'price_cents'=>$price,'compare_cents'=>$compare,
        'tax_rate'=>0,'stock'=>$stock,'low_stock_threshold'=>8,'weight_grams'=>450,
        'dimensions'=>'30 x 20 x 10 cm','warranty'=>'2 year limited warranty','return_eligible'=>1,'return_days'=>30,
        'status'=>'active','is_featured'=>$featured,'is_best_seller'=>$best,'is_new_arrival'=>$new,'is_trending'=>$trend,
        'is_digital'=>0,'rating_avg'=>0,'rating_count'=>0,'sold_count'=>max(0, 240 - $pIndex*7),
        'seo_title'=>$name.' | AllStore','seo_description'=>$short,'video_url'=>'','supplier_name'=>$brand.' Supply Co.',
        'created_at'=>gmdate('Y-m-d\TH:i:s\Z', time() - ($pIndex * 3 + 2) * 86400),'updated_at'=>$now,
    ]);
    $gallery = [$img[$image], $img[array_keys($img)[($pIndex+2)%8]], $img[array_keys($img)[($pIndex+5)%8]]];
    foreach ($gallery as $gi=>$gurl) {
        allstore_seed_row('product_images', ['id'=>allstore_seed_uuid(),'product_id'=>$id,'url'=>$gurl,'alt'=>$name,'sort_order'=>$gi,'is_primary'=>$gi===0?1:0,'created_at'=>$now]);
    }
    allstore_seed_row('inventory', ['id'=>allstore_seed_uuid(),'product_id'=>$id,'variant_id'=>'','warehouse_id'=>$warehouseId,
        'quantity'=>$stock,'reserved'=>0,'damaged'=>0,'available'=>$stock,'updated_at'=>$now]);
    // Dynamic attribute values (used by category filters).
    $colorKeys = ['charcoal','ivory','sand','olive','silver','tan'];
    allstore_seed_row('product_attribute_values', ['product_id'=>$id,'attribute_id'=>$attrIds['Color'],'value_id'=>$valueIds['Color:'.$colorKeys[$pIndex%6]],'value_text'=>null]);
    allstore_seed_row('product_attribute_values', ['product_id'=>$id,'attribute_id'=>$attrIds['Material'],'value_id'=>$valueIds['Material:'.['leather','cotton','aluminium','oak','glass'][$pIndex%5]],'value_text'=>null]);
    if ($pIndex % 3 === 0) {
        allstore_seed_row('product_attribute_values', ['product_id'=>$id,'attribute_id'=>$attrIds['Size'],'value_id'=>$valueIds['Size:'.['s','m','l','xl'][$pIndex%4]],'value_text'=>null]);
    }
    // Variants for selected products.
    if (in_array($pIndex, [0,1,2,3,4], true)) {
        $variantSets = [
            0=>[['Charcoal','charcoal',0],['Ivory','ivory',1000]],
            1=>[['128 GB','128gb',-10000],['256 GB','256gb',0],['512 GB','512gb',15000]],
            2=>[['8 GB / 256 GB','8gb',-15000],['16 GB / 512 GB','16gb',0]],
            3=>[['Size 40','40',0],['Size 42','42',0],['Size 44','42',0]],
            4=>[['Tan Leather','tan',0],['Steel Bracelet','silver',4000]],
        ];
        foreach ($variantSets[$pIndex] as $vi=>[$vname,$vslug,$delta]) {
            allstore_seed_row('product_variants', ['id'=>allstore_seed_uuid(),'product_id'=>$id,'sku'=>'AS-'.($pIndex+1).'-V'.($vi+1),
                'barcode'=>'','name'=>$vname,'options'=>Helpers::json(['variant'=>$vname]),
                'price_cents'=>$price+$delta,'compare_cents'=>$compare+$delta,
                'stock'=>max(3,(int)($stock/3)),'image_url'=>$img[$image],'is_active'=>1,
                'created_at'=>$now,'updated_at'=>$now]);
        }
    }
    $pIndex++;
}

/* ---------------------------------------------------- CMS & homepage builder */
$bannerId = allstore_seed_uuid();
allstore_seed_row('banners', ['id'=>$bannerId,'title'=>'Objects worth keeping','subtitle'=>'A considered collection for everyday life',
    'image_url'=>'/assets/img/hero-marketplace.jpg','mobile_image_url'=>'/assets/img/hero-marketplace.jpg',
    'link_url'=>'/shop','placement'=>'hero','sort_order'=>0,'starts_at'=>null,'expires_at'=>null,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
allstore_seed_row('banners', ['id'=>allstore_seed_uuid(),'title'=>'The everyday edit','subtitle'=>'Up to 30% off selected essentials',
    'image_url'=>'/assets/img/promo-wide.jpg','mobile_image_url'=>'/assets/img/promo-wide.jpg',
    'link_url'=>'/deals','placement'=>'promo','sort_order'=>0,'starts_at'=>null,'expires_at'=>null,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
$sections = [
    ['hero_slider','','','{}',1], ['categories','Shop by category','Explore every corner of the store','{"limit":8}',2],
    ['flash_sale','Flash sale','Limited-time prices, while stock lasts','{"limit":6}',3],
    ['best_sellers','Best sellers','The pieces our customers come back for','{"limit":8}',4],
    ['trending','Trending now','What everyone is looking at this week','{"limit":8}',5],
    ['promo_banners','','','{}',6],
    ['new_arrivals','New arrivals','Freshly added to the collection','{"limit":8}',7],
    ['brands','Featured brands','Makers we trust','{"limit":8}',8],
    ['deals','Deals & offers','Considered pieces, better prices','{"limit":8}',9],
    ['recommended','Recommended for you','A few favourites from our editors','{"limit":8}',10],
    ['recently_viewed','Recently viewed','Pick up where you left off','{"limit":6}',11],
    ['newsletter','Stay in the loop','New arrivals and member-only offers','{}',12],
];
foreach ($sections as [$k,$t,$s,$cfg,$sort]) {
    allstore_seed_row('homepage_sections', ['id'=>allstore_seed_uuid(),'section_key'=>$k,'title'=>$t,'subtitle'=>$s,'config'=>$cfg,
        'sort_order'=>$sort,'is_enabled'=>1,'starts_at'=>null,'expires_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
}
$flashId = allstore_seed_uuid();
allstore_seed_row('flash_sales', ['id'=>$flashId,'name'=>'Weekend Flash Sale','starts_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400),
    'ends_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3*86400),'is_active'=>1,'created_at'=>$now]);
foreach (array_slice($productIds,0,6) as $i=>$pid) {
    $prices = [24900,89900,129900,12900,21900,47900];
    allstore_seed_row('flash_sale_items', ['id'=>allstore_seed_uuid(),'flash_sale_id'=>$flashId,'product_id'=>$pid,
        'discount_percent'=>20,'sale_price_cents'=>(int)round($prices[$i]*0.8),'sort_order'=>$i]);
}
foreach ([
    ['WELCOME10','percent',10,0,5000,0,0,0,0,0],
    ['SAVE20','fixed',0,2000,12000,2500,100,1,0,0],
    ['FREESHIP','free_shipping',0,0,7500,0,0,0,1,1],
    ['FIRST15','percent',15,0,10000,3000,50,1,1,0],
] as [$code,$type,$pct,$val,$min,$max,$limit,$perUser,$first,$free]) {
    allstore_seed_row('coupons', ['id'=>allstore_seed_uuid(),'code'=>$code,'type'=>$type,'percent'=>$pct,'value_cents'=>$val,
        'min_order_cents'=>$min,'max_discount_cents'=>$max,'category_id'=>null,'product_id'=>null,
        'usage_limit'=>$limit,'per_user_limit'=>$perUser,'used_count'=>0,
        'starts_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+90*86400),
        'first_order_only'=>$first,'free_shipping'=>$free,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now]);
}
$zone = allstore_seed_uuid();
allstore_seed_row('shipping_zones', ['id'=>$zone,'name'=>'United States','countries'=>Helpers::json(['US','*']),'regions'=>'[]','cities'=>'[]','is_active'=>1,'sort_order'=>0,'created_at'=>$now]);
allstore_seed_row('shipping_rates', ['id'=>allstore_seed_uuid(),'zone_id'=>$zone,'name'=>'Standard Delivery','method'=>'standard','min_weight_grams'=>0,
    'max_weight_grams'=>0,'min_order_cents'=>0,'cost_cents'=>799,'free_above_cents'=>7500,
    'estimated_days_min'=>3,'estimated_days_max'=>6,'is_active'=>1,'created_at'=>$now]);
allstore_seed_row('shipping_rates', ['id'=>allstore_seed_uuid(),'zone_id'=>$zone,'name'=>'Express Delivery','method'=>'express','min_weight_grams'=>0,
    'max_weight_grams'=>0,'min_order_cents'=>0,'cost_cents'=>1499,'free_above_cents'=>0,
    'estimated_days_min'=>1,'estimated_days_max'=>2,'is_active'=>1,'created_at'=>$now]);
foreach ([['AllStore Express','ASE','https://tracking.example.com/%s'],['ParcelCo','PARCEL','https://parcel.example.com/track/%s']] as [$n,$c,$t]) {
    allstore_seed_row('couriers', ['id'=>allstore_seed_uuid(),'name'=>$n,'code'=>$c,'tracking_url_template'=>$t,'api_base'=>'','is_active'=>1,'created_at'=>$now]);
}
$pageTexts = [
    'about'=>['About AllStore','AllStore is a modern marketplace built around a simple idea: everyday objects should be useful, well made and easy to find. We work with independent makers and established brands to bring a considered selection into one place.\n\nOur team checks every product for quality, packaging and accuracy before it reaches the store. We keep the experience calm and uncluttered, with clear pricing, honest descriptions and straightforward returns.'],
    'contact'=>['Contact us','We are here to help with orders, returns and product questions. Use the contact form or email hello@allstore.example. Our support team typically replies within one business day.'],
    'privacy'=>['Privacy Policy','We collect only the information needed to process orders and operate your account: name, email, delivery address and order history. Passwords are stored as one-way hashes. We never sell personal data. Payment details are handled by the payment provider and never stored on our servers. You may request a copy or deletion of your data by contacting support.'],
    'terms'=>['Terms & Conditions','By placing an order you agree to the prices, delivery estimates and return terms shown at checkout. Products remain the property of AllStore until payment is confirmed. We may cancel an order if an item is unavailable or if fraud is suspected. These terms are governed by applicable consumer law.'],
    'returns'=>['Return & Refund Policy','Most items can be returned within 30 days of delivery in original condition. Open a return request from your account; once approved and received, a refund is issued to the original payment method. Refunds are marked complete only after the payment provider or an administrator confirms them.'],
    'shipping'=>['Shipping Policy','Orders are packed at our main warehouse. Standard delivery takes 3–6 business days and express delivery 1–2 business days. Shipping is complimentary on orders over $75. Tracking details are added to your account once a parcel is handed to the courier.'],
];
foreach ($pageTexts as $slug=>[$title,$body]) {
    allstore_seed_row('pages', ['id'=>allstore_seed_uuid(),'slug'=>$slug,'title'=>$title,'body'=>$body,'seo_title'=>$title.' | AllStore',
        'seo_description'=>mb_substr($body,0,150),'is_published'=>1,'created_at'=>$now,'updated_at'=>$now]);
}
$faqs = [
    ['How long does delivery take?','Standard delivery takes 3–6 business days. Express delivery takes 1–2 business days.','shipping'],
    ['Can I change or cancel my order?','Yes, while the order is pending, confirmed or processing. Open the order in your account and choose Cancel.','orders'],
    ['How do returns work?','Open a return request within 30 days of delivery. Once approved and received, we issue a refund to the original payment method.','returns'],
    ['Do you ship internationally?','Shipping zones are configured by the store administrator. Available options appear at checkout for your country.','shipping'],
    ['Is my payment information stored?','No. Card details are handled by the payment provider. We only store an order reference and payment status.','payments'],
    ['How do I use a coupon?','Enter the code in your cart or at checkout. Coupon rules, minimum spend and expiry are validated on the server.','promotions'],
    ['Can I order without an account?','Yes, guest checkout is supported. Creating an account lets you track orders and save addresses.','account'],
    ['When will I get my refund?','After a return is approved and received, refunds are processed by the payment provider. The status is shown in your account.','returns'],
];
foreach ($faqs as $i=>[$q,$a,$c]) { allstore_seed_row('faqs', ['id'=>allstore_seed_uuid(),'question'=>$q,'answer'=>$a,'category'=>$c,'sort_order'=>$i,'is_published'=>1,'created_at'=>$now]); }
$blogs = [
    ['A considered desk setup','Small changes that make a workspace calmer and more useful.','The best desk is the one you forget about. Start with a single good light, a keyboard that feels right and one object that makes the space yours.','/assets/img/promo-wide.jpg'],
    ['Materials that age well','Why leather, oak and brushed steel are worth the wait.','Patina is not damage; it is a record of use. We look at three materials that get better with time and how to care for them.','/assets/img/products/watch.jpg'],
    ['The everyday coffee ritual','A simple pour-over routine for slower mornings.','You do not need a lot of equipment for a good cup: a dripper, fresh beans, water just off the boil and five quiet minutes.','/assets/img/products/coffee.jpg'],
    ['Packing for a long weekend','A short list of things that earn their place.','The trick is to choose layers, not extras. A weekender, one pair of shoes, a watch and something to read.','/assets/img/hero-marketplace.jpg'],
];
foreach ($blogs as $i=>[$t,$e,$b,$image]) {
    allstore_seed_row('blogs', ['id'=>allstore_seed_uuid(),'slug'=>Helpers::slugify($t),'title'=>$t,'excerpt'=>$e,'body'=>$b,
        'image_url'=>$image,'author'=>'AllStore Editorial','is_published'=>1,
        'published_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-($i+1)*7*86400),
        'seo_title'=>$t.' | AllStore Journal','seo_description'=>$e,'created_at'=>$now,'updated_at'=>$now]);
}

/* --------------------------------------------- demo content (no credentials) */
// Two demo customer profiles with an unguessable, disposable random password.
// They exist only so seeded reviews/orders have valid foreign keys. No one can
// sign in as them; real accounts must be created through /register.
$demoUsers = [];
foreach (['Casey Morgan','Riley Chen'] as $n) {
    $uid = allstore_seed_uuid(); $demoUsers[] = $uid;
    allstore_seed_row('profiles', ['id'=>$uid,'email'=>strtolower(str_replace(' ','.',$n)).'@example.test',
        'password_hash'=>password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
        'full_name'=>$n,'phone'=>'+1 555 010 0000','role_id'=>$roleIds['customer'],'status'=>'active',
        'email_verified'=>1,'newsletter_opt_in'=>0,'created_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-40*86400),'updated_at'=>$now]);
}
$reviewTexts = [
    [5,'Exactly as described','Beautifully finished and arrived a day early. The packaging was careful and minimal.'],
    [5,'A daily favourite','I have used this every day since it arrived. Quiet, well made and easy to live with.'],
    [4,'Great value','Very good quality for the price. Would happily buy from this brand again.'],
    [5,'Thoughtful details','It is the small things that stand out here. Everything feels considered.'],
    [4,'Solid choice','Works well and looks better in person than in the photos.'],
    [5,'Would recommend','Straightforward ordering, quick delivery and a product that does what it promises.'],
];
foreach ($reviewTexts as $i=>[$rating,$title,$body]) {
    $pid = $productIds[$i % 8];
    allstore_seed_row('reviews', ['id'=>allstore_seed_uuid(),'product_id'=>$pid,'user_id'=>$demoUsers[$i%2],'order_id'=>null,
        'rating'=>$rating,'title'=>$title,'body'=>$body,'image_urls'=>'[]','is_verified'=>0,'status'=>'approved',
        'admin_reply'=>null,'report_count'=>0,'created_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-($i+2)*86400),'updated_at'=>$now]);
}
foreach (array_unique(array_slice($productIds,0,8)) as $pid) {
    $r = Database::first("SELECT AVG(rating) AS a, COUNT(*) AS n FROM reviews WHERE product_id=:p AND status='approved'", ['p'=>$pid]);
    Database::update('products', ['rating_avg'=>round((float)($r['a'] ?? 0),2),'rating_count'=>(int)($r['n'] ?? 0)], 'id=:i', ['i'=>$pid]);
}
// Sample orders for dashboard analytics (seed data, not fabricated runtime state).
$statuses = ['delivered','delivered','shipped','processing','pending'];
foreach ($statuses as $oi=>$status) {
    $oid = allstore_seed_uuid(); $num = 'AS-' . gmdate('Ymd', time()-($oi+1)*86400) . '-SEED' . $oi;
    $pid = $productIds[($oi*3) % count($productIds)];
    $p = Database::first('SELECT name,sku,price_cents FROM products WHERE id=:i', ['i'=>$pid]);
    $qty = $oi % 2 + 1; $subtotal = (int)$p['price_cents'] * $qty;
    $placed = gmdate('Y-m-d\TH:i:s\Z', time()-($oi+1)*86400);
    allstore_seed_row('orders', ['id'=>$oid,'order_number'=>$num,'user_id'=>$demoUsers[$oi%2],'guest_email'=>null,
        'status'=>$status,'payment_status'=>$status === 'pending' ? 'pending' : 'paid','payment_method'=>$oi%2 ? 'cod' : 'stripe',
        'shipping_method'=>'standard','subtotal_cents'=>$subtotal,'discount_cents'=>0,'shipping_cents'=>799,
        'tax_cents'=>0,'total_cents'=>$subtotal+799,'coupon_id'=>null,'coupon_code'=>null,'currency'=>'USD',
        'customer_email'=>Database::value('SELECT email FROM profiles WHERE id=:i',['i'=>$demoUsers[$oi%2]]),
        'customer_phone'=>'+1 555 010 0000','customer_name'=>Database::value('SELECT full_name FROM profiles WHERE id=:i',['i'=>$demoUsers[$oi%2]]),
        'shipping_address'=>Helpers::json(['full_name'=>'Demo','line1'=>'24 Market Street','city'=>'San Francisco','country'=>'US']),
        'billing_address'=>null,'customer_note'=>null,'idempotency_key'=>'seed-'.$oi,'ip_address'=>'127.0.0.1',
        'user_agent'=>'seed','placed_at'=>$placed,'created_at'=>$placed,'updated_at'=>$placed]);
    allstore_seed_row('order_items', ['id'=>allstore_seed_uuid(),'order_id'=>$oid,'product_id'=>$pid,'variant_id'=>'','name'=>$p['name'],'sku'=>$p['sku'],
        'image_url'=>'','options'=>'{}','unit_price_cents'=>(int)$p['price_cents'],'quantity'=>$qty,
        'discount_cents'=>0,'tax_cents'=>0,'total_cents'=>$subtotal,'created_at'=>$placed]);
    allstore_seed_row('order_status_history', ['id'=>allstore_seed_uuid(),'order_id'=>$oid,'from_status'=>null,'to_status'=>'pending','note'=>'Seed','changed_by'=>null,'created_at'=>$placed]);
    allstore_seed_row('payments', ['id'=>allstore_seed_uuid(),'order_id'=>$oid,'provider'=>$oi%2?'cod':'stripe','method'=>$oi%2?'cod':'stripe',
        'status'=>$status === 'pending'?'pending':'paid','amount_cents'=>$subtotal+799,'currency'=>'USD',
        'transaction_ref'=>'seed','created_at'=>$placed,'updated_at'=>$placed]);
    allstore_seed_row('shipments', ['id'=>allstore_seed_uuid(),'order_id'=>$oid,'status'=>$status==='delivered'?'delivered':'pending',
        'estimated_delivery'=>gmdate('Y-m-d\TH:i:s\Z',time()+3*86400),'created_at'=>$placed,'updated_at'=>$placed]);
    allstore_seed_row('invoices', ['id'=>allstore_seed_uuid(),'order_id'=>$oid,'invoice_number'=>'INV-'.$num,'created_at'=>$placed]);
}
allstore_seed_output("Seed complete: " . count($productIds) . " products, " . count($catIds) . " categories, " . count($brandIds) . " brands.\n");
