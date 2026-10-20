# J Slug Translate

中文標題的文章**第一次發佈**時，用 Google Gemini 自動產生英文網址（slug）。

用來取代 [Simple Slug Translate](https://wordpress.org/plugins/simple-slug-translate/)：那個外掛靠 IBM Watson Language Translator 翻譯，這個服務在 2024-12-10 全面下架，外掛從 2023 年起也沒再更新。

- 版本：1.0.3
- 需求：WordPress、PHP 7.4 以上、Gemini API 金鑰（[Google AI Studio](https://aistudio.google.com/app/apikey) 免費申請）
- 授權：GPL-2.0-or-later
- 作者：[傑哥架站教室](https://jclassroom.net)

**下載**：到 [Releases](https://github.com/lacostechiu/j-slug-translate/releases/latest) 下載 `JSlugTranslate.zip`，在 WordPress 後台「外掛 → 安裝外掛 → 上傳外掛」安裝。請不要用 GitHub 的「Code → Download ZIP」，那個壓縮檔的資料夾名稱不同，之後更新時會被 WordPress 當成另一個外掛。

---

## 核心原則：網址只產生一次，產生後永遠不動

網址一旦被搜尋引擎收錄、被分享到 LINE 或 FB，再改就會造成失效連結。所以外掛只處理「**從來沒有過網址**」的文章，其他情況一律不碰。

| 情況 | 網址會不會變 |
|---|---|
| 全新作品第一次發佈 | **會**：用 Gemini 把標題翻成英文 |
| 草稿還沒發佈前改了好幾次標題 | 發佈那一刻才用最後的標題翻一次，之後就固定 |
| 已發佈的作品改標題 | 不會 |
| 預約發佈中改標題 | 不會 |
| 業主自己在編輯器填了英文網址 | 不會，以業主填的為準 |
| 曾經上線、改回草稿、再重新發佈 | 不會，保留原本的網址（1.0.1 修正） |
| 外掛安裝前就存在的中文網址文章 | 不會，不做批次改名 |
| Gemini 翻譯失敗（逾時、金鑰錯誤、回傳怪內容） | 不會，保留 WordPress 原本產生的中文網址，發佈照常進行 |

判斷方式：文章要同時符合下面幾點才會翻譯。

1. 文章類型在設定裡有勾選。
2. 這次儲存的狀態是「發佈」或「預約發佈」。
3. WordPress 產生的網址含有 `%`，代表是中文（或其他非英數字）編碼出來的。
4. 資料庫裡這篇文章的網址是空的，代表從沒發佈過。

### 為什麼不批次把舊的中文網址改成英文

中文網址對排名幾乎沒有影響：Google 看得懂，台灣使用者也用中文搜尋。批次改名要靠 301 轉址撐住所有已收錄、已分享的舊網址，為了很小的差異去冒險不划算。中文網址真正的缺點在分享時很醜、長標題會被截斷，這些只要從新文章開始改善就夠了。

---

## 分類與標籤

設定頁可以另外勾選分類法。勾選後，**新增**的分類或標籤如果名稱是中文，會自動產生英文 slug；已存在的分類和標籤不會被改動。

---

## 安裝與設定

1. 從 [Releases](https://github.com/lacostechiu/j-slug-translate/releases/latest) 下載 `JSlugTranslate.zip`，上傳並啟用。zip 裡的資料夾名稱固定是 `JSlugTranslate/`，更新時才會覆蓋原本的外掛，不會裝成第二份。
2. 在 `wp-config.php` 的 `/* That's all, stop editing! */` 上方加入金鑰：

   ```php
   define( 'JST_GEMINI_API_KEY', '你的金鑰' );
   ```

   也可以填在設定頁，但寫在 wp-config 比較安全，金鑰不會存進資料庫，也不會跟著備份或搬站外流。兩邊都有填時，以 wp-config 為準。
3. 到後台「設定 → Slug 翻譯」：
   - 還沒有金鑰的話，點金鑰欄位說明裡的「Google AI Studio」連結去申請。
   - 勾選要翻譯的文章類型（預設只有「文章」）。
   - 用「測試翻譯」輸入一個中文標題，確認能正常回傳英文。
   - 有錯誤時，頁面下方會顯示「最近一次錯誤」。

### 換模型

預設模型是 `gemini-2.5-flash`。Google 下架舊模型時，到設定頁的「模型」欄位改名稱即可，不用改程式。

---

## 翻譯規則

提示詞寫在 `jst_prompt()`，重點如下：

- 2～6 個英文單字，小寫，用連字號連接。
- 翻譯意思，**不用拼音**。兩個字的詩意命名（例如「雲棲」）也要意譯。
- 標題本身已經有英文時，優先沿用。
- 「▎」後面的內容標籤當作後綴，例如：設計師開箱 = `designer-tour`、總監開箱 = `director-tour`、客戶回饋 = `client-review`、MV = `mv`。
- 結果只接受英數字和連字號，最長 80 字元，超過時在連字號處截斷。
- 網址重複時，WordPress 會自動加上 `-2`、`-3`。

實測範例：

| 標題 | 網址 |
|---|---|
| 晨曦微光 ▎設計師開箱 | `dawn-light-designer-tour` |
| 雲棲 | `cloud-dwelling` |
| 靜謐之所 | `place-of-serenity` |
| 木與光的對話 | `wood-light-dialogue` |

同一個標題一小時內只呼叫一次 API。區塊編輯器一次儲存會送出兩次請求，這樣可以避免重複呼叫。

---

## 開發者 hook

| Hook | 用途 |
|---|---|
| `jst_prompt` | 修改送給 Gemini 的提示詞。參數：`$prompt, $title` |
| `jst_pre_translate` | 回傳非 `null` 的字串，就會跳過 Gemini，直接使用該字串。可以用來改接其他翻譯服務，或在測試時替換翻譯結果。參數：`null, $title` |
| `jst_translated_slug` | 修改 Gemini 翻譯完成後的 slug。參數：`$slug, $title` |

### 測試時注意

想用「預約到很久以後」的文章來測試，避免測試文章出現在前台時：WordPress 用 `wp_update_post()` 更新草稿時會重設發佈日期，除非同時傳入 `'edit_date' => true`，否則文章會被**立即發佈**。比較安全的做法是用 `jst_pre_translate` 替換翻譯結果，直接呼叫 `jst_filter_post_data()` 測試判斷邏輯，完全不存檔。

---

## 更新紀錄

- **1.0.3**（2026-10-20）：公開到 GitHub。提示詞範例改用中性標題，補上授權與作者資訊。
- **1.0.2**（2026-10-20）：設定頁的「Google AI Studio」改成連到申請 API 金鑰頁面的超連結。
- **1.0.1**（2026-10-20）：曾經有過網址的文章（例如上線後改回草稿再重新發佈）不再重新翻譯，避免舊網址失效。
- **1.0.0**（2026-10-20）：第一版。以 Gemini 取代已下架的 IBM Watson。
