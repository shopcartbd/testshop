# AllStore — বাস্তবায়ন পরিকল্পনা

## বর্তমান অবস্থা ও ভিত্তি

`testshop` রিপোতে আগে শুধু README ছিল। সংযুক্ত নির্দেশনার ভিত্তিতে PHP 8.4-ভিত্তিক, Vercel Container runtime-এ চালানো যায় এমন সার্ভার-রেন্ডার্ড স্টোরফ্রন্ট তৈরি হবে। Vercel-এর official runtime তালিকায় PHP Function runtime নেই; তবে FrankenPHP-সহ Docker container সমর্থিত। ডেটাবেস হবে Supabase PostgreSQL; credentials ছাড়া live database operation বা production verification করা যাবে না। AllStore নামটি আপাতত placeholder এবং environment/config থেকে পরিবর্তনযোগ্য হবে।

## নকশা

সম্ভাব্য নকশার দিক: editorial retail magazine (0.04), quiet Scandinavian catalog (0.07), neon-tech marketplace (0.03)। বেছে নেওয়া দিক: **editorial retail magazine**, ব্যবহারকারীর চাওয়া premium marketplace অনুভূতি ও সম্পাদিত product photography-এর সঙ্গে সবচেয়ে মানানসই।

- **Design Movement:** আধুনিক editorial commerce; বড় ছবি, সংযত টাইপ, সূক্ষ্ম grid এবং পরিষ্কার whitespace।
- **Core Principles:** পণ্য-ছবিকে অগ্রাধিকার; সহজে স্ক্যানযোগ্য hierarchy; সংযত রং; মোবাইলে সমান যত্ন।
- **Color Philosophy:** উষ্ণ ivory canvas, গভীর evergreen brand accent, ink-dark লেখা এবং muted clay detail—ভরসা ও কারুশিল্পের ভাব।
- **Layout Paradigm:** বিস্তৃত editorial hero ও category narrative; নিচের অংশে পরিষ্কার responsive product grid।
- **Signature Elements:** চার-কোণা compass-যুক্ত bag mark; সবুজ pill CTA; পাতলা warm-gray card border।
- **Interaction Philosophy:** তাৎক্ষণিক কিন্তু শান্ত—স্পষ্ট hover/focus, form feedback, অপ্রয়োজনীয় motion নয়।
- **Animation:** সংক্ষিপ্ত opacity/translate transitions; reduced-motion preference সম্মান করা।
- **Typography:** system sans-serif stack দিয়ে নির্ভরযোগ্য বাংলা ও ইংরেজি rendering; বড় editorial heading, আরামদায়ক body scale।
- **Brand Essence:** দৈনন্দিন কেনাকাটার নানা প্রয়োজনকে একটি পরিচ্ছন্ন, বেছে নেওয়া marketplace-এ আনা। ব্যক্তিত্ব: নির্ভরযোগ্য, পরিশীলিত, সহজ।
- **Brand Voice:** সোজাসাপ্টা, উষ্ণ, পরামর্শমূলক। উদাহরণ: “প্রতিদিনের পছন্দ, একটু ভালোভাবে বেছে নেওয়া।” “নতুন আবিষ্কার দেখুন।”
- **Wordmark & Logo:** আলাদা geometric shopping-bag/compass symbol-এর পাশে AllStore wordmark; mark টি ছোট icon-এও পাঠযোগ্য।
- **Signature Brand Color:** গভীর evergreen।

## বাস্তবায়ন কাঠামো

- `public/`: public entrypoint, CSS, JS এবং static assets।
- `app/`: runtime configuration, DB access, catalog/cart/order logic এবং reusable view helpers।
- `views/`: shared header/footer, home, shop, product, cart ও checkout template।
- `database/migrations/`: Supabase/PostgreSQL schema migration।
- `docs/`: environment, security, API ও deployment নির্দেশনা।
- Root `Dockerfile`/`Caddyfile`: FrankenPHP container deployment; Vercel Git integration push-এর পর deployment চালাতে পারে।

## সীমা

এই রিপোতে Supabase বা Vercel credentials দেওয়া নেই। তাই schema ও environment contract প্রস্তুত করা সম্ভব, কিন্তু live data, storage policy, admin bootstrap, production DB/storage connectivity এবং Vercel deployment URL credentials/configuration ছাড়া যাচাই করা যাবে না। সংযুক্ত prompt-এর প্রতিটি বিস্তৃত feature—যেমন সম্পূর্ণ admin CMS, email reset, payment/courier, reports ও full user account suite—কাজ না করে implemented বলা হবে না।