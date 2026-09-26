# Manual steps

Almost everything in the dataset track runs by itself. These few steps need a person, because a
website asks for a form, a free sign-up, or a browser (and the rules say the pipeline must never
get around those). **No step costs money, and none needs any coding.**

Each step says where to save the file. After you have done the steps you want, run
`data\run_data.bat download` (built in stage D2): it looks in these folders and uses whatever it
finds. **You can skip any step** - the pipeline then uses the fallback described under
"If you skip this", and says so in its report.

All folders below are inside the project folder, `D:\SIH_MAIN`. To open one, paste its path into
the address bar of File Explorer. You never need to create a folder: every folder named below is
created for you (with an empty `.gitkeep` file inside) each time `data\run_data.bat download` runs.

| # | Step | About | Recommended? | If skipped |
|---|---|---|---|---|
| 1 | Coal mine list from Global Energy Monitor | 5 min | **Yes - biggest effect** | Mines get less precise locations |
| 2 | Two Wikidata downloads | 5 min | **Yes** | 20 mines cannot be placed on the map |
| 3 | Free air-quality key from OpenAQ | 5 min | Yes | Air-quality data is simulated instead of real |
| 4 | Save three company web pages | 5 min | Optional - sites often unreachable | Areas come from the Coal Directory, or stay empty |
| 5 | CPCB pollution-law book (PDF) | 2 min | Yes | Environmental rules are not cited |
| 6 | One gazette notification (PDF) | 10 min | Optional | One legal question stays open |
| 7 | Environmental clearance letters | 20 min | Optional | Nothing is lost |
| 8 | Second PPE photo set (Kaggle) | 10 min | Optional | Only one test photo set is used |

---

## 1. Coal mine list from Global Energy Monitor

**Why:** it holds the real location, owner and size of coal mines worldwide. We use it to put our
mines on the map accurately.

1. Open <https://globalenergymonitor.org/download-data>
2. If a cookie box appears, click **No, thanks**.
3. Scroll down to the section called **Coal**.
4. Find the card titled **Coal mines** ("Global Coal Mine Tracker"). Click the small **switch** in
   the top-right corner of that card so it turns on.
5. A box appears at the side saying *1 dataset selected*. Click **Download Selected Items**.
6. The website may ask for your name, email or how you will use the data. Fill it in - this is the
   part the pipeline cannot do for you. (We could not see this screen without submitting it, so
   follow what it shows.)
7. Save the Excel file (`.xlsx`) into: `D:\SIH_MAIN\data\raw\gem_gcmt\`
   Keep the file name the website gives it.

**If you skip this:** mines are placed using Wikidata (step 2) where possible, otherwise at the
centre of their district. Those locations are marked as less precise.

---

## 2. Two Wikidata downloads

**Why:** Wikidata is a free public database. It gives a second source of mine locations, and it
tells us which new districts were split from which old ones - needed for 20 of our mines.

Do this twice, once for each link:

| File to save | Link |
|---|---|
| `coal_mines_india.csv` | [Open query 1 - coal mines in India](https://query.wikidata.org/#SELECT%20%3Fmine%20%3FmineLabel%20%3Fcoord%20%3Foperator%20%3FoperatorLabel%20%3Fplace%20%3FplaceLabel%20WHERE%20%7B%0A%20%20%3Fmine%20wdt%3AP31%2Fwdt%3AP279%2A%20wd%3AQ959309%20%3B%0A%20%20%20%20%20%20%20%20wdt%3AP17%20wd%3AQ668%20.%0A%20%20OPTIONAL%20%7B%20%3Fmine%20wdt%3AP625%20%3Fcoord%20%7D%0A%20%20OPTIONAL%20%7B%20%3Fmine%20wdt%3AP137%20%3Foperator%20%7D%0A%20%20OPTIONAL%20%7B%20%3Fmine%20wdt%3AP131%20%3Fplace%20%7D%0A%20%20SERVICE%20wikibase%3Alabel%20%7B%20bd%3AserviceParam%20wikibase%3Alanguage%20%22en%22.%20%7D%0A%7D%0AORDER%20BY%20%3FmineLabel) |
| `districts_india.csv` | [Open query 2 - districts of India](https://query.wikidata.org/#SELECT%20%3Fdistrict%20%3FdistrictLabel%20%3Fcoord%20%3Fparent%20%3FparentLabel%20%3Finception%20%3FseparatedFrom%20%3FseparatedFromLabel%20WHERE%20%7B%0A%20%20%3Fdistrict%20wdt%3AP31%20wd%3AQ1149652%20.%0A%20%20OPTIONAL%20%7B%20%3Fdistrict%20wdt%3AP625%20%3Fcoord%20%7D%0A%20%20OPTIONAL%20%7B%20%3Fdistrict%20wdt%3AP131%20%3Fparent%20%7D%0A%20%20OPTIONAL%20%7B%20%3Fdistrict%20wdt%3AP571%20%3Finception%20%7D%0A%20%20OPTIONAL%20%7B%20%3Fdistrict%20wdt%3AP807%20%3FseparatedFrom%20%7D%0A%20%20SERVICE%20wikibase%3Alabel%20%7B%20bd%3AserviceParam%20wikibase%3Alanguage%20%22en%22.%20%7D%0A%7D%0AORDER%20BY%20%3FdistrictLabel) |

1. Click the link. The Wikidata page opens with the question already typed in - you do not need
   to change anything.
2. Click the blue **▶ Run** button on the left side of the page. Wait until a table of results
   appears below (a few seconds, sometimes up to a minute).
3. Above the results, click **Download** and choose **CSV file**.
4. Rename the downloaded file to the name in the table above and save it into:
   `D:\SIH_MAIN\data\raw\wikidata\`

(The same questions are saved in `data\scripts\wikidata\` if the links ever stop working: open
<https://query.wikidata.org>, paste the text of the `.rq` file, and continue from step 2.)

**If you skip this:** mines not found in step 1 go to their district centre, and the 20 mines in
districts created after 2011 cannot be placed at all - they are listed as *TODO-VERIFY*.

---

## 3. Free air-quality key from OpenAQ

**Why:** OpenAQ publishes real daily air-quality readings from government stations near coalfields.
It needs a free personal key.

1. Open <https://explore.openaq.org/register> and create a free account.
2. Open <https://explore.openaq.org/account> and copy your **API key**.
3. In File Explorer, open `D:\SIH_MAIN`. Find the file **`.env.example`**. Copy it, paste it in the
   same folder, and rename the copy to exactly **`.env`** (nothing before the dot).
4. Open `.env` with Notepad. On the line `OPENAQ_API_KEY=`, paste your key right after the `=`,
   with no spaces. Save.
   *If Notepad offers to save it as `.env.txt`, choose "Save as type: All files" and keep the name `.env`.*

Keep your key private. `.env` is never uploaded to GitHub - the project is set up to ignore it.

**If you skip this:** the pipeline creates air-quality readings from documented ranges instead, and
marks them clearly as simulated.

---

## 4. (Optional) Save three company web pages

**Why:** these three coal companies' websites block automatic downloads, so a person would have to
save their list of areas. (We do not try to get around the block.) **The sites are often
unreachable from a normal browser as well** - this happened during testing on 2026-09-26 - so
this step is optional, and skipping it is fine.

| Company | Page to open | Save into |
|---|---|---|
| Eastern Coalfields (ECL) | <https://www.easterncoal.nic.in/> - then open its **Areas** page | `D:\SIH_MAIN\data\raw\company_sites\ecl\` |
| Central Coalfields (CCL) | <https://www.centralcoalfields.in/cmpny/areas.php> | `D:\SIH_MAIN\data\raw\company_sites\ccl\` |
| South Eastern Coalfields (SECL) | <https://www.secl-cil.in/coalfield.php> | `D:\SIH_MAIN\data\raw\company_sites\secl\` |

If a page does open:

1. Open the page in Chrome or Edge.
2. Press **Ctrl + S**.
3. Under "Save as type", choose **Webpage, HTML only**.
4. Save it into the folder in the table (the folder already exists).

If a page does not open, move on - there is nothing else to do.

**If you skip this:** the pipeline looks for area-wise tables for these companies in the Coal
Directory of India 2024-25 (downloaded automatically) and uses them, citing the chapter and sheet,
if they exist. If they do not, these companies' mines are left without an area.

---

## 5. CPCB pollution-law book (PDF)

**Why:** it contains the official text of the Water, Air and Environment Protection Acts, so our
environmental rules can cite a clause. CPCB's website does not allow automatic downloads.

1. Open <https://cpcb.nic.in/7thEditionPollutionControlLawSeries2021.pdf>
2. When the PDF shows, click the **download** icon (a downward arrow, top right).
3. Save it into: `D:\SIH_MAIN\data\raw\legal\environment\`

**If you skip this:** environmental rules are still listed, but marked *TODO-VERIFY* because they
have no clause to cite.

---

## 6. (Optional) One gazette notification

**Why:** it settles whether the Employees' Provident Fund Act, 1952 is still in force - which
matters for contractors' monthly EPF documents. The notice we need is **S.O. 2060(E), dated
3 May 2023**, from the Ministry of Labour and Employment. The gazette's search page uses a
picture code (CAPTCHA), which only a person may solve.

1. Open <https://egazette.gov.in>
2. Use the site's search for notifications. Search for the Ministry of **Labour and Employment**,
   dated **03-05-2023**, and look for the one numbered **S.O. 2060(E)**. The search will ask you to
   type the letters shown in a picture.
   (We could not see the search screens, so their exact names may differ.)
3. Open it and save the PDF into: `D:\SIH_MAIN\data\raw\legal\notifications\`

**If you skip this:** the EPF Act's status stays *TODO-VERIFY*, and contractor EPF deadlines are not
tied to a legal clause.

---

## 7. (Optional) Environmental clearance letters

**Why:** 5 to 10 real clearance letters for coal mines give examples of clearance conditions and
test documents for reading text from scans later.

1. Open <https://parivesh.nic.in/newupgrade/>
2. Search for clearances granted to coal mining projects - ideally for mines near Jharia, Singrauli,
   Korba, Raniganj or Talcher.
3. Download 5 to 10 clearance letters (PDF).
4. Save them into: `D:\SIH_MAIN\data\raw\parivesh\`

(The portal works only in a browser; we did not go through its search screens, so follow what it shows.)

**If you skip this:** nothing is lost - this source is optional.

---

## 8. (Optional) Second PPE photo set from Kaggle

**Why:** a second, larger set of labelled photos (SH17, about 8,000 images) to test the helmet and
vest detector. One photo set already downloads automatically, so this is extra.

1. Open <https://www.kaggle.com/datasets/mugheesahmad/sh17-dataset-for-ppe-detection>
2. Sign in to Kaggle (a free account).
3. Read the licence shown on the page. If it allows research use, click **Download**.
4. Save the zip file into: `D:\SIH_MAIN\data\raw\ppe\sh17\`

**If you skip this:** testing uses only the automatic photo set.
