# Sources

Generated from `data/sources.yaml` by `data/scripts/render_sources_md.py` - edit the YAML,
not this file. Every URL was found on the source's own page and checked by
`data/scripts/probe_sources.py` (robots.txt respected, at most 1 request per second per host).
Discovered 2026-09-25. SHA-256 checksums are recorded when files are downloaded (stage D2).

## Summary

| ID | Source | Access | Automatic files | Size known | Download (D2) | Licence |
|---|---|---|---|---|---|---|
| S01 | Existing repo seed (74 mines) | In the repo | 0 | - | ok (in repo) | Repository content (prototype demo data) |
| S02 | Global Coal Mine Tracker (GCMT) | Manual - web form | 0 | - | ok | CC BY 4.0 - confirmed from the licence notice inside each downloaded workbook (3 of 3) |
| S03 | DataMeet maps - district and state boundaries | Automatic | 10 | 26.2 MB | ok | Districts - CC BY 2.5 India; other data in the repo - CC BY 4.0; repo code - MIT |
| S04 | Wikidata - coal mines and districts of India | Manual - in a browser | 0 | - | ok | CC0 1.0 (Wikidata structured data) |
| S05 | Company websites - areas of each coal company | Automatic (+3 manual) | 6 | 278 KB | ok | Not located on the sites checked; treated as all rights reserved |
| S06 | Ministry of Coal - monthly statistics and Coal Directory of India | Automatic | 15 | 8.9 MB | ok | Not located (no copyright-policy link found on coal.gov.in); treated as all rights reserved |
| S07 | DGMS - accident statistics and analyses | Automatic | 4 | 13.4 MB | ok | Not located (copyright-policy page content not machine-readable); treated as all rights reserved |
| S08 | MSHA Open Government Data | Automatic | 8 | 242.1 MB | ok | Not stated on the page. US federal government works are generally not subject to copyright - TODO-VERIFY against a Department of Labor statement. |
| S09 | OpenAQ API v3 - air quality near coalfields (CPCB stations) | Manual - free API key | 0 | - | ok | Per data provider - read from /v3/licenses once a key is available, recorded in D2 |
| S10 | Legal texts and their current status | Automatic (+2 manual) | 25 | 34.1 MB | ok (+1 pending-manual) | Official Government of India legal texts; the texts of Acts and notifications are public documents - reproduction terms TODO-VERIFY |
| S11 | Environmental clearance letters (OPTIONAL) | Manual - in a browser, optional | 0 | - | skipped-manual | Not located; treated as all rights reserved |
| S12 | Tender and award data (OPTIONAL) | Skipped, optional | 0 | - | skipped (see notes for fallback) | n/a (not used) |
| S13 | PPE detection evaluation set (OPTIONAL) | Automatic (+1 manual), optional | 1 | 208.7 MB | ok (+1 pending-manual) | CC BY 4.0 (stated in the dataset's own README.dataset.txt and data.yaml, Roboflow project ppe-detection-ozhfb v14) |

Automatic downloads total about **533.7 MB** (pages served without a size are not counted).

## Legal status (S10)

Each status was read from the notification itself on 2026-09-25.

| Instrument | Status | Basis |
|---|---|---|
| Occupational Safety, Health and Working Conditions Code, 2020 (37 of 2020) | in force - all provisions, from 2025-11-21 | S.O. 5321(E) dated 21.11.2025, Gazette of India Extraordinary Pt II Sec 3(ii), No. 5145 ("appoints the 21st day of November, 2025 as the date on which the provisions of the said Code, shall come into force" - no exceptions) |
| Occupational Safety, Health and Working Conditions (Central) Rules, 2026 | in force from 2026-05-08 | G.S.R. 345(E) dated 08.05.2026, Gazette of India Extraordinary Pt II Sec 3(i), No. 311; rule 1(3) - in force on publication. Draft was G.S.R. 934(E) dated 30.12.2025. |
| Code on Wages, 2019 (29 of 2019) | partly in force - from 2025-11-21, except the sub-provisions left out of the schedule | S.O. 5322(E) dated 21.11.2025, Gazette No. 5146 - brings in ss.1-41, 42(4)-(9), 43-66, 67 (listed parts), 68, and 69 except serial 3 of S.O. 4604(E) dated 18.12.2020 (S.O. 4604(E) not read - TODO-VERIFY) |
| Code on Social Security, 2020 (36 of 2020) | partly in force - most provisions from 2025-11-21; some earlier under S.O. 2060(E) of 03.05.2023 | S.O. 5319(E) dated 21.11.2025, Gazette No. 5143, as corrected by S.O. 5936(E) dated 19.12.2025 (Gazette 20.12.2025, 8589 GI/2025) |
| Industrial Relations Code, 2020 (35 of 2020) | in force - all provisions, from 2025-11-21 | S.O. 5320(E) dated 21.11.2025, Gazette No. 5144 (no exceptions) |
| Mines Act, 1952 (35 of 1952) | repealed from 2025-11-21 (subsumed into the OSH Code) | OSH Code s.143(1)(c), commenced by S.O. 5321(E). Savings, s.143(3) - rules, regulations, notifications etc. made under it are deemed made under the Code and "remain in force to the extent they are not contrary to the provisions of this Code till they are repealed by the Central Government"; s.143(4) applies s.6 of the General Clauses Act, 1897. |
| Mines Rules, 1955 | repealed (superseded) from 2026-05-08; between 2025-11-21 and 2026-05-08 it continued under OSH Code s.143(3) | G.S.R. 345(E) dated 08.05.2026, preamble "in supersession of" item 3, "except as respects things done or omitted to be done before such supersession" |
| Coal Mines Regulations, 2017 | in force under the savings clause (deemed made under the OSH Code) | OSH Code s.143(3); not in the supersession list of G.S.R. 345(E). A replacement, the Draft OSH&WC (Coal Mines) Regulations, 2026, was published by DGMS on 31.01.2026 as a draft; no final notification was found on DGMS's gazette-notification page on 2026-09-25. |
| Mines Vocational Training Rules, 1966 | repealed (superseded) from 2026-05-08 | G.S.R. 345(E) dated 08.05.2026, preamble "in supersession of" item 5 |
| Contract Labour (Regulation and Abolition) Act, 1970 (37 of 1970) | repealed from 2025-11-21 (subsumed into the OSH Code) | OSH Code s.143(1)(h), commenced by S.O. 5321(E); savings as for the Mines Act (s.143(3)) |
| Contract Labour (Regulation and Abolition) Central Rules, 1971 | repealed (superseded) from 2026-05-08 | G.S.R. 345(E) dated 08.05.2026, preamble "in supersession of" item 8 |
| Employees' Provident Funds and Miscellaneous Provisions Act, 1952 (19 of 1952) | TODO-VERIFY | Code on Social Security s.164(1) item 3. S.O. 5319(E) as issued commenced items 1-2 and 4-9 only (item 3 left out); the corrigendum S.O. 5936(E) re-worded that entry to "sub-section (1) of section 164 except the provisions of the Code specified at serial number (vi) of S.O.2060(E), dated the 3rd May, 2023". S.O. 2060(E) itself was not retrieved from an official source (the EPFO circular reproducing it now returns 404), so the effect on the EPF Act is not concluded here. Not in the brief's list; included because contractor EPF challans depend on it. |
| Coal Mines Provident Fund and Miscellaneous Provisions Act, 1948 | in force (not repealed by any of the four Codes) | Not among the enactments repealed by Code on Social Security s.164(1) (read in full - the Act is not named anywhere in the Code). Official consolidated text not yet retrieved (India Code unreachable to automated clients) - amendments TODO-VERIFY. |
| Environment (Protection) Act, 1986; Air (Prevention and Control of Pollution) Act, 1981; Water (Prevention and Control of Pollution) Act, 1974 | in force (outside the labour codes) | Not among the enactments repealed by OSH Code s.143(1) or Code on Social Security s.164(1) (both read). Latest official consolidated text located - CPCB Pollution Control Law Series, 7th edition (2021). Amendments after 2021 not verified from an official source - TODO-VERIFY. |

## S01 - Existing repo seed (74 mines)

- **Publisher:** this repository
- **Landing page:** backend/data/seed/
- **Saved to:** `data/reference/`
- **Purpose:** The 74 mines, their mine-head logins and current demo standing.
- **Access:** In the repo
- **Licence:** Repository content (prototype demo data) (this repository)
- **Redistribution:** yes (committed)

Extracted in stage D0 by scripts/extract_mines_base.py; see DATASETS.md.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| mines_base.csv | repo | - | 2026-09-25 | `9ac697e189e00847…` | reference/mines_base.csv |

## S02 - Global Coal Mine Tracker (GCMT)

- **Publisher:** Global Energy Monitor
- **Landing page:** https://globalenergymonitor.org/download-data
- **Saved to:** `data/raw/gem_gcmt/`
- **Purpose:** Real mine coordinates, owner, capacity, status and type, for matching our 74 mines.
- **Access:** Manual - web form - see `MANUAL_STEPS.md`
- **Licence:** CC BY 4.0 - confirmed from the licence notice inside each downloaded workbook (3 of 3) (https://globalenergymonitor.org/creative-commons-license)
- **Redistribution:** allowed with attribution; raw file kept out of git anyway

The download is selected on GEM's "Download data" page (card "Coal mines", last update shown as May 2026; the project page says the most recent release, August 2026, is the second version of the May 2026 dataset). The download widget is built by JavaScript and may ask for details before releasing the file, so it cannot be fetched automatically. GEM hosts the full CC BY 4.0 text; its statement that this licence covers the tracker data was not found in the static page, so the licence is confirmed from the file's own notice when it arrives. Fallback if skipped: Wikidata (S04), then district centroids (S03). Project page (reference only): https://globalenergymonitor.org/projects/global-coal-mine-tracker

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| `raw/gem_gcmt/Global Coal Mine Tracker, August 2026.xlsx` | present (manual) | 2.9 MB | 2026-09-26 | `9aa804479c4b7645…` | added by hand |
| `raw/gem_gcmt/Global-Coal-Mine-Tracker-December-2024-Supplement-Historical-Production-from-2018-to-2023.xlsx` | present (manual) | 324 KB | 2026-09-26 | `1ae19635e8972322…` | added by hand |
| `raw/gem_gcmt/Global-Coal-Mine-Tracker-September-2024-Supplement-v2.xlsx` | present (manual) | 379 KB | 2026-09-26 | `1c3fc4eb146e32b2…` | added by hand |

## S03 - DataMeet maps - district and state boundaries

- **Publisher:** DataMeet India community
- **Landing page:** https://github.com/datameet/maps
- **Saved to:** `data/raw/datameet/`
- **Purpose:** District polygons for centroid fallback locations and the GIS layer.
- **Access:** Automatic
- **Licence:** Districts - CC BY 2.5 India; other data in the repo - CC BY 4.0; repo code - MIT (https://github.com/datameet/maps/blob/b3fbbde595310b397a55d718e0958ce249a4fa1f/Districts/README.md)
- **Redistribution:** allowed with attribution
- **Pinned commit:** `b3fbbde595310b397a55d718e0958ce249a4fa1f`

URLs are pinned to commit b3fbbde (2022-05-11) so checksums stay stable. These are Census 2011 districts: 9 of the 38 (state, district) pairs in mines_base.csv (20 mines) are not found by name - spelling variants (Angul/Anugul, Purulia/Puruliya, Bardhaman/Barddhaman), districts created after 2011 (Bhadradri, Mancherial, Peddapalli), and cities recorded as districts in the seed (Asansol, Raniganj, Ramagundam). Stage D3 needs a cited crosswalk (Wikidata "separated from", S04) before centroids can be taken.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| 2011_Dist.shp | ok | 9.7 MB | 2026-09-25 | `3636e627a519f67b…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/Districts/Census_2011/2011_Dist.shp |
| 2011_Dist.shx | ok | 5 KB | 2026-09-25 | `12b6028dfac9e3f2…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/Districts/Census_2011/2011_Dist.shx |
| 2011_Dist.dbf | ok | 53 KB | 2026-09-25 | `2c7b03578fdf41a4…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/Districts/Census_2011/2011_Dist.dbf |
| 2011_Dist.prj | ok | 0 KB | 2026-09-25 | `a02a27b1d1982c85…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/Districts/Census_2011/2011_Dist.prj |
| Admin2.shp | ok | 16.3 MB | 2026-09-25 | `b61ebc11a7487ce1…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/States/Admin2.shp |
| Admin2.shx | ok | 0 KB | 2026-09-25 | `dc428a44ed20384b…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/States/Admin2.shx |
| Admin2.dbf | ok | 2 KB | 2026-09-25 | `4ec2f9b83263c857…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/States/Admin2.dbf |
| Admin2.prj | ok | 0 KB | 2026-09-25 | `a02a27b1d1982c85…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/States/Admin2.prj |
| Admin2.cpg | ok | 0 KB | 2026-09-25 | `3ad3031f5503a440…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/States/Admin2.cpg |
| Districts README (licence) | ok | 1 KB | 2026-09-25 | `179605e797b5cfd0…` | https://raw.githubusercontent.com/datameet/maps/b3fbbde595310b397a55d718e0958ce249a4fa1f/Districts/README.md |

## S04 - Wikidata - coal mines and districts of India

- **Publisher:** Wikimedia Foundation / Wikidata contributors
- **Landing page:** https://query.wikidata.org/
- **Saved to:** `data/raw/wikidata/`
- **Purpose:** Second location fallback for mines; "separated from" links for the post-2011 district crosswalk.
- **Access:** Manual - in a browser - see `MANUAL_STEPS.md`
- **Licence:** CC0 1.0 (Wikidata structured data) (https://www.wikidata.org/wiki/Wikidata:Licensing)
- **Redistribution:** True

query.wikidata.org/robots.txt disallows /sparql for all user agents, and www.wikidata.org robots.txt disallows /w/api.php, /w/rest.php and Special:EntityData, so no automated access is used. The two queries are saved in data/scripts/wikidata/ and run by hand in the browser (a person using the site is not a crawler). Every item and property ID in them was checked against its Wikidata page on 2026-09-25.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| `raw/wikidata/coal_mines_india.csv` | present (manual) | 14 KB | 2026-09-26 | `f445c45085efdc56…` | added by hand |
| `raw/wikidata/districts_india.csv` | present (manual) | 138 KB | 2026-09-26 | `2f690badd833ba6c…` | added by hand |

## S05 - Company websites - areas of each coal company

- **Publisher:** Coal India Ltd and subsidiaries, SCCL, NLC India
- **Landing page:** https://www.coalindia.in/
- **Saved to:** `data/raw/company_sites/`
- **Purpose:** Company -> area lists (areas.csv).
- **Access:** Automatic - see `MANUAL_STEPS.md`
- **Licence:** Not located on the sites checked; treated as all rights reserved
- **Redistribution:** no - saved pages stay in raw/ (gitignored); only facts (area names) are used, with the page cited

Mixed access. Automatic: BCCL, WCL, MCL, SCCL, NCL, NLC. Manual (save the page from a browser): ECL - both easterncoal.nic.in and easterncoal.gov.in timed out from this network; CCL - the server answers automated clients with an empty HTTP 202 (a bot challenge); SECL - HTTP 403 to automated clients. None of these blocks is bypassed. NCL at first failed TLS verification; verifying with the operating system's certificate store (truststore) fixed it, and its robots.txt allows all. CIL (holding company) and CMPDI (planning institute) have no operating areas. NCL is organised by projects rather than areas.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| BCCL areas page | ok | 63 KB | 2026-09-25 | `29fcb924b63b0eb1…` | https://bcclweb.in/?page_id=6102 |
| WCL areas page | ok | 121 KB | 2026-09-25 | `9e556819a675f7d6…` | https://www.westerncoal.in/en/areas |
| MCL coalfields/areas page | ok | 41 KB | 2026-09-25 | `5794b52284d489b2…` | https://www.mahanadicoal.in/About/eareas.php |
| SCCL contact page (lists areas) | ok | 25 KB | 2026-09-25 | `906467ef7e654363…` | https://scclmines.com/scclnew/contact-us.asp |
| NCL overview (projects) | ok | 3 KB | 2026-09-25 | `6f52582a0372d4c4…` | https://www.nclcil.in/detail/647634/ncl-overview |
| NLC India current projects page (lists its mines) | ok | 25 KB | 2026-09-25 | `f973a8f91a7e967c…` | https://www.nlcindia.in/website/en/aboutus/nlcilprojects/currentprojects.html |
| ECL areas page (manual) | manual | - | - | - | https://www.easterncoal.nic.in/ |
| CCL areas page (manual) | manual | - | - | - | https://www.centralcoalfields.in/cmpny/areas.php |
| SECL areas page (manual) | manual | - | - | - | https://www.secl-cil.in/coalfield.php |

## S06 - Ministry of Coal - monthly statistics and Coal Directory of India

- **Publisher:** Ministry of Coal, Government of India
- **Landing page:** https://coal.gov.in/public-information/monthly-statistics-at-glance
- **Saved to:** `data/raw/moc/`
- **Purpose:** Company-wise monthly coal production (calibration base) and the Coal Directory 2024-25.
- **Access:** Automatic
- **Licence:** Not located (no copyright-policy link found on coal.gov.in); treated as all rights reserved
- **Redistribution:** no - raw files stay in raw/ (gitignored); figures are used with page citations

Page 1 of each "Monthly Coal Statistics" PDF is a company-wise table (ECL, BCCL, CCL, NCL, WCL, SECL, MCL, NEC, CIL total, SCCL, captives): monthly target, achievement, same month last year, year-to-date, in million tonnes (checked on msg-Aug26.pdf). The generated window is 2026-06-28 to 2026-09-25; September 2026 is not yet published, so September 2025 is kept as the seasonal stand-in and must be labelled as such. NLC India (lignite) is not in this table. NEC (North Eastern Coalfields) is one of the eight rows summed in the CIL total; its August 2026 production was 0.00 Mt. Of the five seed mines with operator "Coal India Ltd", only AS-DIB-70 is in Assam (the others are JH-BOK-07, JH-LAT-10, CG-SUR-31 and CG-BIL-38), so NEC is not a stand-in for them; D3 keeps them as CIL (corrected in D3 - D1 said all five were in Assam). The Coal Directory 2024-25 chapters were checked in D3 for area-wise tables (the fallback for ECL/CCL/SECL areas): none has one. Older Coal Directory PDFs (48-56 MB) are skipped as redundant with the 2024-25 Excel chapters.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| Monthly Coal Statistics Jun 2026 | ok | 1.4 MB | 2026-09-25 | `1b8345c4ea04f79c…` | https://coal.gov.in/sites/default/files/2026-07/msg-june26.pdf |
| Monthly Coal Statistics Jul 2026 | ok | 1.7 MB | 2026-09-25 | `24e74afb921389af…` | https://coal.gov.in/sites/default/files/2026-08/msg-july26.pdf |
| Monthly Coal Statistics Aug 2026 | ok | 1.7 MB | 2026-09-25 | `5e4dc88bea916e99…` | https://coal.gov.in/sites/default/files/2026-09/msg-Aug26.pdf |
| Monthly Coal Statistics Sep 2025 (stand-in for Sep 2026) | ok | 2.1 MB | 2026-09-25 | `a519edfdace54a49…` | https://coal.gov.in/sites/default/files/2025-10/msg-setp25.pdf |
| Coal Directory 2024-25 Chapter 1 | ok | 177 KB | 2026-09-25 | `7359051e9136fdac…` | https://coal.gov.in/sites/default/files/2024-03/cdchap1.xlsx |
| Coal Directory 2024-25 Chapter 2 | ok | 568 KB | 2026-09-25 | `bf21651c4818bc2c…` | https://coal.gov.in/sites/default/files/2024-03/cdchap2.xlsx |
| Coal Directory 2024-25 Chapter 3 | ok | 325 KB | 2026-09-25 | `cfa952ffd113c8c2…` | https://coal.gov.in/sites/default/files/2024-03/cdchap3.xlsx |
| Coal Directory 2024-25 Chapter 4 | ok | 123 KB | 2026-09-25 | `fcef372206eb500e…` | https://coal.gov.in/sites/default/files/2024-03/cdchap4.xlsx |
| Coal Directory 2024-25 Chapter 5 | ok | 102 KB | 2026-09-25 | `e30eb985582419a8…` | https://coal.gov.in/sites/default/files/2024-03/cdchap5.xlsx |
| Coal Directory 2024-25 Chapter 6 | ok | 236 KB | 2026-09-25 | `93c2dd3c9fbf2c3b…` | https://coal.gov.in/sites/default/files/2024-03/cdchap6.xlsx |
| Coal Directory 2024-25 Chapter 7 | ok | 151 KB | 2026-09-25 | `4171a1329d5ffd5b…` | https://coal.gov.in/sites/default/files/2024-03/cdchap7.xlsx |
| Coal Directory 2024-25 Chapter 8 | ok | 137 KB | 2026-09-25 | `c56cccd1308c3d88…` | https://coal.gov.in/sites/default/files/2024-03/cdchap8.xlsx |
| Coal Directory 2024-25 Chapter 9 | ok | 80 KB | 2026-09-25 | `f93cc0387da1dd5a…` | https://coal.gov.in/sites/default/files/2024-03/cdchap9.xlsx |
| Coal Directory 2024-25 Chapter 10 | ok | 162 KB | 2026-09-25 | `ea815c4ff683b553…` | https://coal.gov.in/sites/default/files/2024-03/cdchap10.xlsx |
| Coal Directory 2024-25 Chapter 11 | ok | 68 KB | 2026-09-25 | `d3b03a9cfacfc76d…` | https://coal.gov.in/sites/default/files/2024-03/cdchap11.xlsx |

## S07 - DGMS - accident statistics and analyses

- **Publisher:** Directorate General of Mines Safety, Ministry of Labour & Employment
- **Landing page:** https://www.dgms.gov.in/
- **Saved to:** `data/raw/dgms/`
- **Purpose:** Accident counts by cause, fatalities and serious injuries in coal mines.
- **Access:** Automatic
- **Licence:** Not located (copyright-policy page content not machine-readable); treated as all rights reserved (https://www.dgms.gov.in/UserView/index?mid=1272)
- **Redistribution:** no - raw files stay in raw/; figures used with page citations

DGMS's accident and statistics portal (accident-statistics.dgms.gov.in) is login-only and is not used. Public material found: annual reports up to 2014 (the most recent annual report located as a public PDF), a 2024 "Key Evaluation of Trends in Coal Mine Accidents", and a 2025 bulletin. Whether the 2024/2025 documents carry cause-wise tables is checked in D3. dgms.gov.in answers HEAD requests with a 404 page for files it serves normally, so it is probed with GET.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| Key Evaluation of Trends in Coal Mine Accidents (2024) | ok | 2.2 MB | 2026-09-25 | `9fb5c1a4139daa3c…` | https://www.dgms.gov.in/writereaddata/UploadFile/sanket0404_2024.pdf |
| DGMS Bulletin 2025 | ok | 6.5 MB | 2026-09-25 | `ac3c65ef72faad04…` | https://www.dgms.gov.in/writereaddata/UploadFile/BulletinNew2025AJ_04092025.pdf |
| DGMS Annual Report 2014 (English) | ok | 4.5 MB | 2026-09-25 | `d6f5c211b90d393f…` | https://www.dgms.gov.in/writereaddata/UploadFile/DGMS_Annual_Report_2014_Eng-14.pdf |
| DGMS Statutory Framework note | ok | 199 KB | 2026-09-25 | `930fdb054d7a784a…` | https://www.dgms.gov.in/writereaddata/Content/STATUTORYFRAMEWORK.pdf |

## S08 - MSHA Open Government Data

- **Publisher:** US Mine Safety and Health Administration
- **Landing page:** https://arlweb.msha.gov/OpenGovernmentData/OGIMSHA.asp
- **Saved to:** `data/raw/msha/`
- **Purpose:** Inspection, violation and accident rates for coal mines - the calibration base.
- **Access:** Automatic
- **Licence:** Not stated on the page. US federal government works are generally not subject to copyright - TODO-VERIFY against a Department of Labor statement.
- **Redistribution:** raw zips kept out of git regardless

Pipe-delimited text inside each zip, header row first (stated on the page). The files are re-published every Friday afternoon, so checksums change weekly: D2 must record the snapshot date, and D3 results are reproducible only against that snapshot. Only the four datasets the brief names are taken (about 254 MB zipped), plus their definition files; D2 filters them to coal mines and the last 10 years as parquet.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| Mines.zip | ok | 7.0 MB | 2026-09-25 | `3ddec0aebbc3fd4d…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/Mines.zip |
| Mines definition | ok | 10 KB | 2026-09-25 | `efd2bc2c493207a4…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/Mines_Definition_File.txt |
| Inspections.zip | ok | 70.1 MB | 2026-09-25 | `1ed173526f643ae0…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/Inspections.zip |
| Inspections definition | ok | 6 KB | 2026-09-25 | `f78e21f57e6449da…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/Inspections_Definition_File.txt |
| Violations.zip | ok | 115.1 MB | 2026-09-25 | `5bc4aa7ce494729b…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/Violations.zip |
| Violations definition | ok | 10 KB | 2026-09-25 | `e815bbd8f76a6a01…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/violations_Definition_File.txt |
| Accidents.zip | ok | 49.8 MB | 2026-09-25 | `db5ac677f235e90f…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/Accidents.zip |
| Accidents definition | ok | 10 KB | 2026-09-25 | `c7681808dd372b6c…` | https://arlweb.msha.gov/OpenGovernmentData/DataSets/Accidents_Definition_File.txt |

## S09 - OpenAQ API v3 - air quality near coalfields (CPCB stations)

- **Publisher:** OpenAQ (aggregating CPCB and other providers)
- **Landing page:** https://docs.openaq.org/using-the-api/api-key
- **Saved to:** `data/raw/openaq/`
- **Purpose:** Daily PM10, PM2.5, SO2, NO2 at stations nearest each mine cluster.
- **Access:** Manual - free API key - see `MANUAL_STEPS.md`
- **Licence:** Per data provider - read from /v3/licenses once a key is available, recorded in D2 (https://docs.openaq.org/about/terms)
- **Redistribution:** per provider licence; raw responses kept out of git

Every v3 endpoint, including /v3/licenses, returns 401 without a key (X-API-Key header). Key sign-up is free at explore.openaq.org/register. Clusters from the brief: Dhanbad, Asansol, Korba, Singrauli, Angul/Talcher, Chandrapur, Ramagundam, Neyveli. Fallback if no key: skip, and synthesise environment readings from documented ranges (D4).

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| OpenAQ v3 API base | needs key | - | - | - | https://api.openaq.org/v3/ |

## S10 - Legal texts and their current status

- **Publisher:** Gazette of India, Ministry of Labour & Employment, DGMS, CPCB
- **Landing page:** https://www.dgms.gov.in/UserView/index?mid=1653
- **Saved to:** `data/raw/legal/`
- **Purpose:** Citable status of each instrument; clauses for obligations.csv (D3).
- **Access:** Automatic
- **Licence:** Official Government of India legal texts; the texts of Acts and notifications are public documents - reproduction terms TODO-VERIFY
- **Redistribution:** raw PDFs stay in raw/ (gitignored); obligations cite clause and page

Status determined from the notifications themselves, each read on 2026-09-25 - see the instruments list below. India Code (indiacode.nic.in) does not answer automated clients (its robots.txt times out), and the eGazette search page uses a CAPTCHA, so only direct PDF links are used. Status wording follows the brief: in force / repealed / subsumed / partly in force. CPCB's robots.txt disallows automated access to its PDFs, so the environmental law text is a manual browser download.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| Gazette S.O. 5319(E) - Code on Social Security commencement | ok | 2.2 MB | 2026-09-25 | `4f0bf613ac77aef8…` | https://egazette.gov.in/WriteReadData/2025/267882.pdf |
| Gazette S.O. 5320(E) - Industrial Relations Code commencement | ok | 1.8 MB | 2026-09-25 | `6695757a97e26c4d…` | https://egazette.gov.in/WriteReadData/2025/267883.pdf |
| Gazette S.O. 5321(E) - OSH Code commencement | ok | 1.6 MB | 2026-09-25 | `22212bc1f9e601ad…` | https://egazette.gov.in/WriteReadData/2025/267884.pdf |
| Gazette S.O. 5322(E) - Code on Wages commencement | ok | 2.0 MB | 2026-09-25 | `45105d4da240440c…` | https://egazette.gov.in/WriteReadData/2025/267885.pdf |
| Gazette S.O. 5936(E) - corrigendum to S.O. 5319(E) (Ministry copy) | ok | 547 KB | 2026-09-25 | `d14b088ef05a8f34…` | https://www.labour.gov.in/static/uploads/2026/01/639a9f531898f5a767b1e45e762c2d87.pdf |
| OSH (Central) Rules, 2026 - G.S.R. 345(E), final (DGMS copy) | ok | 1.9 MB | 2026-09-25 | `4a18cf54880519e5…` | https://www.dgms.gov.in/writereaddata/UploadFile/CentralRule_12052026.pdf |
| OSH (Central) Rules - draft, Gazette 8842 GI/2025 (Ministry copy) | ok | 3.7 MB | 2026-09-25 | `30fd3fe095ba719a…` | https://www.labour.gov.in/static/uploads/2026/01/bc7d6ec2898ac322644f2887ee4f5869.pdf |
| OSH Code, 2020 - text (Ministry copy) | ok | 519 KB | 2026-09-25 | `9ce8f68b88f725fb…` | https://www.labour.gov.in/static/uploads/2025/07/36fcfa5d8e6b9145e282bf7b950d6c47.pdf |
| Code on Social Security, 2020 - text (Ministry copy) | ok | 602 KB | 2026-09-25 | `975235e57b8b8c3f…` | https://www.labour.gov.in/static/uploads/2025/07/b0620548445580767b5c0d18c95c26f7.pdf |
| Industrial Relations Code, 2020 - text (Ministry copy) | ok | 444 KB | 2026-09-25 | `43cadbd9ed08dd00…` | https://www.labour.gov.in/static/uploads/2025/07/682a44b5426bff2c1f4943ee1b2fd566.pdf |
| Code on Wages, 2019 - text | not located | - | - | - | raw/legal/codes/ |
| Mines Act, 1952 (DGMS copy) | ok | 150 KB | 2026-09-25 | `af34ab016112fa7a…` | https://www.dgms.gov.in/writereaddata/UploadFile/MinesAct1952.pdf |
| Mines Rules, 1955 (DGMS copy) | ok | 151 KB | 2026-09-25 | `5c5692bb4285b2cf…` | https://www.dgms.gov.in/writereaddata/UploadFile/Mines_Rules_1955.pdf |
| Coal Mines Regulations, 2017 (DGMS copy) | ok | 4.2 MB | 2026-09-25 | `e7702993d2d00db5…` | https://www.dgms.gov.in/writereaddata/UploadFile/Coal_Mines_Regulation_2017_Noti.pdf |
| Mines Vocational Training Rules, 1966 (DGMS copy) | ok | 199 KB | 2026-09-25 | `82ddf3f8511e8a75…` | https://www.dgms.gov.in/writereaddata/UploadFile/MineVocational966.pdf |
| Draft OSH&WC (Coal Mines) Regulations, 2026 (DGMS) | ok | 2.1 MB | 2026-09-25 | `79ed7e7ba71077b7…` | https://www.dgms.gov.in/writereaddata/UploadFile/CMR_GN_31012026.pdf |
| Labour Codes FAQ - which rules apply (Jan 2026) | ok | 343 KB | 2026-09-25 | `cadd78f9b48ebc26…` | https://www.labour.gov.in/static/uploads/2026/01/de4758d5bfeffc456d7de97a801891b0.pdf |
| Additional FAQs on Labour Codes (16.03.2026) | ok | 355 KB | 2026-09-25 | `d55818a3956bebf5…` | https://www.labour.gov.in/static/uploads/2026/03/a4ccf4c6d97c4f1f36a6d83f8c64213d.pdf |
| FAQ on OSH&WC Code (13.03.2026) | ok | 234 KB | 2026-09-25 | `aa0357bbe410d753…` | https://www.labour.gov.in/static/uploads/2026/03/d7a1038bf00f763484aa27a79a4c6306.pdf |
| PIB release - four Labour Codes made effective (transition statement) | ok | 352 KB | 2026-09-25 | `68c2162b5b1bb8bf…` | https://www.pib.gov.in/PressReleseDetailm.aspx?PRID=2192463&reg=3&lang=2 |
| `raw/legal/environment/7thEditionPollutionControlLawSeries2021.pdf` | present (manual) | 12.8 MB | 2026-09-26 | `f63c3f354267a2f3…` | added by hand |
| DGMS (Tech) Circular 04 of 2020 - flammable gas in underground coal mines | ok | 598 KB | 2026-09-25 | `f182642def7223e4…` | https://www.dgms.gov.in/writereaddata/UploadFile/DGMS_04637184121810426988.pdf |
| DGMS circular - environment monitoring system in underground coal mines | ok | 460 KB | 2026-09-25 | `cb8a2c9216b6b68f…` | https://www.dgms.gov.in/writereaddata/UploadFile/Cir_04_Tech_MAMID.pdf |
| DGMS Tech Circular 1 of 2023 - accidents from high-temperature exposure | ok | 4.6 MB | 2026-09-25 | `693add3901f22bee…` | https://www.dgms.gov.in/writereaddata/UploadFile/Circular2023tech.pdf |
| DGMS Tech Circular 1 of 2022 - high atmospheric temperature | ok | 648 KB | 2026-09-25 | `dd4076e2c141659e…` | https://www.dgms.gov.in/writereaddata/UploadFile/DGMS_TECH_CIRCULAR_OH.pdf |
| DGMS (Tech)(OH) Circular 01 of 2021 - first-aid training | ok | 205 KB | 2026-09-25 | `185e362907f8cea5…` | https://www.dgms.gov.in/writereaddata/UploadFile/DGMS_tech.pdf |
| DGMS circular - dust suppression approvals (coal mines) | ok | 4.5 MB | 2026-09-25 | `ed0f06824d136e95…` | https://www.dgms.gov.in/writereaddata/UploadFile/Dus_tsuppresson.pdf |
| EPF Act status - S.O. 2060(E) of 03.05.2023 (manual, optional) | pending-manual | - | - | - | raw/legal/notifications/ |

## S11 - Environmental clearance letters (OPTIONAL)

- **Publisher:** Ministry of Environment, Forest and Climate Change - PARIVESH portal
- **Landing page:** https://parivesh.nic.in/newupgrade/
- **Saved to:** `data/raw/parivesh/`
- **Purpose:** 5-10 EC letters for mines in our list - clearance conditions and an OCR test set.
- **Access:** Manual - in a browser - see `MANUAL_STEPS.md` (optional)
- **Licence:** Not located; treated as all rights reserved
- **Redistribution:** no - raw/ only

robots.txt is absent (404, so allowed), but PARIVESH is a single-page JavaScript app: letters are found through an interactive search backed by the app's private API, which the pipeline does not reverse-engineer. Manual and optional. Fallback: skip.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| EC letters (PDF | pending-manual | - | - | - | raw/parivesh/ |

## S12 - Tender and award data (OPTIONAL)

- **Publisher:** Coal India eProcurement; Central Public Procurement Portal
- **Landing page:** https://coalindiatenders.nic.in/nicgep/app
- **Saved to:** `data/raw/tenders/`
- **Purpose:** Typical contract values and durations by work type.
- **Access:** Skipped (optional)
- **Licence:** n/a (not used)
- **Redistribution:** n/a

Skipped as the brief directs: on both coalindiatenders.nic.in and eprocure.gov.in the "Results of Tenders"/"Bid Awards" and "Tenders Status" pages require a CAPTCHA (checked 2026-09-25). Fallback: contract value ranges from published company annual reports, cited in D3/D4.

## S13 - PPE detection evaluation set (OPTIONAL)

- **Publisher:** Roboflow Universe user "sdp-lfigk", mirrored in GitHub repo vyasdeepti/PPE-Object-Detection-using-YOLO11
- **Landing page:** https://github.com/vyasdeepti/PPE-Object-Detection-using-YOLO11
- **Saved to:** `data/raw/ppe/`
- **Purpose:** Evaluation images for the existing YOLO PPE model.
- **Access:** Automatic (optional)
- **Licence:** CC BY 4.0 (stated in the dataset's own README.dataset.txt and data.yaml, Roboflow project ppe-detection-ozhfb v14) (https://github.com/vyasdeepti/PPE-Object-Detection-using-YOLO11/blob/98085c8a901121d5ec0bf1194f7319a9b792b9cb/dataset/README.dataset.txt)
- **Redistribution:** allowed with attribution; kept out of git (size)
- **Pinned commit:** `98085c8a901121d5ec0bf1194f7319a9b792b9cb`

6 classes: Gloves, Hard_hat, Mask, Person, Safety_boots, Vest (read from data.yaml; the repo README's summary omits vests). The dataset/ folder holds 1,619 images with 1,619 matching label files (counted after extraction in D2; the 2,294 images / 1,832 labels reported in D1 counted the whole repository, including training-run output outside dataset/). The archive also contains a video and model weights; D2 keeps only dataset/. The repository itself has no licence file - the CC BY 4.0 grant comes from the dataset export. Alternative, manual and optional: SH17 (8,099 images) - its GitHub release holds only model weights; the images are on Kaggle, which needs an account key.

| File | Status | Size | Accessed | SHA-256 | URL |
|---|---|---|---|---|---|
| Repository archive at pinned commit (dataset/ kept) | ok | 208.7 MB | 2026-09-25 | `e491c4fe51cb7b09…` | https://codeload.github.com/vyasdeepti/PPE-Object-Detection-using-YOLO11/zip/98085c8a901121d5ec0bf1194f7319a9b792b9cb |
| SH17 dataset via Kaggle (optional, manual) | pending-manual | - | - | - | https://www.kaggle.com/datasets/mugheesahmad/sh17-dataset-for-ppe-detection |
