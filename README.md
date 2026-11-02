# SWS Fake Order Guard

WooCommerce ফেক অর্ডার প্রতিরোধ প্লাগইন — মোবাইল নম্বর যাচাই, নম্বর/IP লিমিট, ফোন নম্বর ব্লকলিস্ট, টেস্ট/এলোমেলো লেখা ব্লক, এবং যাচাই করা অর্ডারে Facebook Purchase ইভেন্ট (Conversions API)।
Developed by [Sabbir Web Solutions](https://sabbirwebsolutions.site)

## যাচাই করা অর্ডারে Facebook Purchase (v1.4.0)

WooCommerce → Fake Order Guard → **যাচাই করা অর্ডারে Facebook Purchase** অংশে Pixel ID আর Conversions API token দিন।

- অর্ডার Completed (বা সেটিংসে বাছাই করা Processing) হলে একবার সার্ভার থেকে Meta-তে Purchase যায়। On-hold/Cancelled/Failed অর্ডারে যায় না।
- ইমেইল, ফোন, নাম, শহর (হ্যাশ করা), IP, ব্রাউজার, `_fbp`/`_fbc` কুকি পাঠানো হয়, তাই ম্যাচ কোয়ালিটি ভালো থাকে।
- PixelYourSite-এর ইভেন্ট আইডি থাকলে সেটাই ব্যবহার হয়, তাই ডুপ্লিকেট হয় না।
- অর্ডার পেজের **Order actions** থেকে হাতে Purchase আবার পাঠানো যায়। ফলাফল অর্ডার নোটে দেখা যায়।
- পিক্সেল প্লাগইনের নিজের Purchase ইভেন্ট বন্ধ রাখুন। "ব্রাউজার Purchase আটকান" অপশন যাচাই না হওয়া অর্ডারের থ্যাংক-ইউ পেজে fbq Purchase আটকায়, কিন্তু অন্য প্লাগইনের সার্ভার-সাইড ইভেন্ট আটকাতে পারে না।

## নতুন আপডেট রিলিজ করার নিয়ম

1. `sws-fake-order-guard.php` ফাইলে দুই জায়গায় ভার্সন বাড়ান: হেডারের `Version:` এবং `const VERSION`।
2. কোড GitHub-এ push করুন (`main` ব্রাঞ্চ)।
3. `sws-fake-order-guard` ফোল্ডারটা zip করুন — zip-এর নাম হবে `sws-fake-order-guard.zip`, ভেতরে `sws-fake-order-guard/` ফোল্ডার।
4. GitHub → Releases → **Draft a new release** → Tag: `v1.4.0` (ভার্সনের সাথে মিলিয়ে) → zip ফাইলটা অ্যাটাচ করে **Publish release**।

১২ ঘণ্টার মধ্যে সব সাইটে "Update now" দেখাবে। সাথে সাথে দেখতে চাইলে Plugins পেজে প্লাগইনের নিচে **Check for updates** ক্লিক করুন।
