"""Stage D3: reference/glossary.csv - mining and governance terms in English, Hindi, Bengali, Odia,
Telugu and Marathi, for consistent UI translation.

DRAFTED BY CLAUDE (the dataset track), NOT REVIEWED: every row has reviewed = false. Translations
are working drafts for the coalfield states' languages (hi, bn, or, te, mr = ISO 639-1). Where a
field uses the English word in everyday speech (dumper, overman, shotfirer), the English loanword
is given, sometimes with a native gloss in brackets. A native speaker with mining vocabulary should
review each language before the text reaches users.
"""

from __future__ import annotations

import csv
import sys

from common import DATA, sha256_file

OUT = DATA / "reference/glossary.csv"
COLUMNS = ["term_id", "en", "hi", "bn", "or", "te", "mr", "definition_en", "domain", "reviewed", "note"]

# term_id, en, hi, bn, or, te, mr, definition, domain
TERMS = [
    ("coal_mine", "coal mine", "कोयला खदान", "কয়লা খনি", "କୋଇଲା ଖଣି", "బొగ్గు గని", "कोळसा खाण", "A mine from which coal is extracted.", "mining"),
    ("opencast_mine", "opencast mine", "खुली खदान", "খোলামুখ খনি", "ଖୋଲାମୁହାଁ ଖଣି", "ఓపెన్‌కాస్ట్ గని", "खुली खाण", "A surface mine worked from an open pit.", "mining"),
    ("underground_mine", "underground mine", "भूमिगत खदान", "ভূগর্ভস্থ খনি", "ଭୂତଳ ଖଣି", "భూగర్భ గని", "भूमिगत खाण", "A mine worked below the surface through shafts or inclines.", "mining"),
    ("coalfield", "coalfield", "कोयला क्षेत्र", "কয়লাক্ষেত্র", "କୋଇଲା କ୍ଷେତ୍ର", "బొగ్గు క్షేత్రం", "कोळसा क्षेत्र", "A region with coal-bearing rock, e.g. Jharia or Talcher.", "mining"),
    ("coal_seam", "coal seam", "कोयला परत", "কয়লার স্তর", "କୋଇଲା ସ୍ତର", "బొగ్గు పొర", "कोळशाचा थर", "A layer of coal in the rock.", "mining"),
    ("overburden", "overburden", "ओवरबर्डन (ऊपरी मिट्टी-चट्टान)", "ওভারবার্ডেন (উপরের মাটি-পাথর)", "ଓଭରବର୍ଡେନ (ଉପରର ମାଟି-ପଥର)", "ఓవర్‌బర్డెన్ (పై మట్టి-రాతి పొర)", "ओव्हरबर्डन (वरची माती-खडक)", "Soil and rock removed to reach coal in an opencast mine.", "mining"),
    ("roof_fall", "roof fall", "छत गिरना", "ছাদ ধস", "ଛାତ ଖସିବା", "పైకప్పు కూలడం", "छत कोसळणे", "Collapse of rock from the roof of an underground working.", "safety"),
    ("side_fall", "side fall", "किनारा गिरना", "পার্শ্ব ধস", "ପାର୍ଶ୍ୱ ଖସିବା", "పక్క గోడ కూలడం", "बाजू कोसळणे", "Collapse of rock from the side of a working or bench.", "safety"),
    ("methane", "methane (firedamp)", "मीथेन (फायरडैम्प)", "মিথেন (ফায়ারড্যাম্প)", "ମିଥେନ (ଫାୟାରଡ୍ୟାମ୍ପ)", "మీథేన్ (ఫైర్‌డాంప్)", "मिथेन (फायरडॅम्प)", "Inflammable gas released from coal.", "safety"),
    ("gas_testing", "gas testing", "गैस परीक्षण", "গ্যাস পরীক্ষা", "ଗ୍ୟାସ ପରୀକ୍ଷା", "వాయు పరీక్ష", "वायू चाचणी", "Measuring gas concentrations in mine air.", "safety"),
    ("ventilation", "ventilation", "संवातन", "বায়ুচলাচল", "ବାୟୁ ଚଳାଚଳ", "వాయు ప్రసరణ", "वायुवीजन", "Supply of fresh air through the mine workings.", "safety"),
    ("respirable_dust", "respirable dust", "श्वसनीय धूल", "শ্বাসযোগ্য ধুলো", "ଶ୍ୱାସଯୋଗ୍ୟ ଧୂଳି", "శ్వాసించదగిన ధూళి", "श्वसनीय धूळ", "Fine dust that can reach the lungs.", "health"),
    ("explosives", "explosives", "विस्फोटक", "বিস্ফোরক", "ବିସ୍ଫୋରକ", "పేలుడు పదార్థాలు", "स्फोटके", "Materials used for blasting rock or coal.", "safety"),
    ("blasting", "blasting", "ब्लास्टिंग (विस्फोटन)", "ব্লাস্টিং (বিস্ফোরণ)", "ବ୍ଲାଷ୍ଟିଂ (ବିସ୍ଫୋରଣ)", "బ్లాస్టింగ్ (పేల్చడం)", "ब्लास्टिंग (स्फोटन)", "Breaking rock or coal with explosives.", "safety"),
    ("shotfirer", "shotfirer", "शॉटफायरर", "শটফায়ারার", "ସଟଫାୟାରର", "షాట్‌ఫైరర్", "शॉटफायरर", "Competent person who fires shots (blasts).", "roles"),
    ("haul_road", "haul road", "ढुलाई सड़क", "পরিবহন রাস্তা", "ପରିବହନ ରାସ୍ତା", "రవాణా రహదారి", "वाहतूक रस्ता", "Road used by dumpers in an opencast mine.", "mining"),
    ("dumper", "dumper", "डम्पर", "ডাম্পার", "ଡମ୍ପର", "డంపర్", "डंपर", "Heavy truck that carries coal or overburden.", "mining"),
    ("conveyor_belt", "conveyor belt", "कन्वेयर बेल्ट", "কনভেয়ার বেল্ট", "କନଭେୟର ବେଲ୍ଟ", "కన్వేయర్ బెల్ట్", "कन्व्हेयर बेल्ट", "Moving belt that carries coal.", "mining"),
    ("winding", "winding (hoisting)", "वाइंडिंग (उत्थापन)", "উইন্ডিং (উত্তোলন)", "ୱାଇଣ୍ଡିଂ (ଉତ୍ତୋଳନ)", "వైండింగ్ (ఎత్తడం)", "वाइंडिंग (उचल यंत्रणा)", "Raising and lowering people or material in a shaft.", "mining"),
    ("shaft", "shaft", "शाफ्ट (कूपक)", "শ্যাফট", "ଶାଫ୍ଟ", "షాఫ్ట్ (గని బావి)", "शाफ्ट (खाण विहीर)", "Vertical opening from the surface to underground workings.", "mining"),
    ("mine_manager", "mine manager", "खान प्रबंधक", "খনি ম্যানেজার", "ଖଣି ପରିଚାଳକ", "గని మేనేజర్", "खाण व्यवस्थापक", "Person in charge of the mine's day-to-day safety and operation.", "roles"),
    ("overman", "overman", "ओवरमैन", "ওভারম্যান", "ଓଭରମ୍ୟାନ", "ఓవర్‌మ్యాన్", "ओव्हरमन", "Supervisor of a district or section of a mine.", "roles"),
    ("mining_sirdar", "mining sirdar", "माइनिंग सरदार", "মাইনিং সর্দার", "ମାଇନିଂ ସର୍ଦ୍ଦାର", "మైనింగ్ సర్దార్", "मायनिंग सरदार", "First-line supervisor who inspects working places.", "roles"),
    ("contractor", "contractor", "ठेकेदार", "ঠিকাদার", "ଠିକାଦାର", "కాంట్రాక్టర్ (గుత్తేదారు)", "कंत्राटदार", "Firm supplying labour or services to the mine under contract.", "labour"),
    ("contract_worker", "contract worker", "ठेका श्रमिक", "চুক্তিভিত্তিক শ্রমিক", "ଠିକା ଶ୍ରମିକ", "కాంట్రాక్ట్ కార్మికుడు", "कंत्राटी कामगार", "Worker employed through a contractor.", "labour"),
    ("licence", "licence", "अनुज्ञप्ति (लाइसेंस)", "লাইসেন্স", "ଅନୁଜ୍ଞାପତ୍ର (ଲାଇସେନ୍ସ)", "లైసెన్సు", "परवाना", "Official permission, e.g. a contractor's licence.", "labour"),
    ("medical_exam", "medical examination", "चिकित्सा परीक्षा", "স্বাস্থ্য পরীক্ষা", "ସ୍ୱାସ୍ଥ୍ୟ ପରୀକ୍ଷା", "వైద్య పరీక్ష", "वैद्यकीय तपासणी", "Health check of a worker before and during employment.", "health"),
    ("vocational_training", "vocational training", "व्यावसायिक प्रशिक्षण", "বৃত্তিমূলক প্রশিক্ষণ", "ବୃତ୍ତିଗତ ପ୍ରଶିକ୍ଷଣ", "వృత్తి శిక్షణ", "व्यावसायिक प्रशिक्षण", "Safety and job training required for mine workers.", "labour"),
    ("ppe", "personal protective equipment (PPE)", "व्यक्तिगत सुरक्षा उपकरण", "ব্যক্তিগত সুরক্ষা সরঞ্জাম", "ବ୍ୟକ୍ତିଗତ ସୁରକ୍ଷା ଉପକରଣ", "వ్యక్తిగత రక్షణ పరికరాలు", "वैयक्तिक संरक्षक उपकरणे", "Helmets, boots, gloves and similar protective gear.", "safety"),
    ("safety_helmet", "safety helmet", "सुरक्षा हेलमेट", "সুরক্ষা হেলমেট", "ସୁରକ୍ଷା ହେଲମେଟ", "భద్రతా హెల్మెట్", "सुरक्षा हेल्मेट", "Hard hat worn to protect the head.", "safety"),
    ("safety_boots", "safety boots", "सुरक्षा जूते", "সুরক্ষা জুতো", "ସୁରକ୍ଷା ଜୋତା", "భద్రతా బూట్లు", "सुरक्षा बूट", "Protective footwear.", "safety"),
    ("hi_vis_vest", "high-visibility vest", "हाई-विज़िबिलिटी जैकेट", "হাই-ভিজিবিলিটি জ্যাকেট", "ହାଇ-ଭିଜିବିଲିଟି ଜ୍ୟାକେଟ", "హై-విజిబిలిటీ జాకెట్", "हाय-व्हिजिबिलिटी जॅकेट", "Reflective vest that makes a person visible to vehicle drivers.", "safety"),
    ("self_rescuer", "self-rescuer", "सेल्फ-रेस्क्यूअर (आत्म-बचाव उपकरण)", "সেলফ-রেসকিউয়ার (আত্মরক্ষা যন্ত্র)", "ସେଲ୍ଫ-ରେସ୍କ୍ୟୁଅର (ଆତ୍ମରକ୍ଷା ଉପକରଣ)", "సెల్ఫ్-రెస్క్యూయర్ (స్వీయ రక్షణ పరికరం)", "सेल्फ-रेस्क्युअर (स्वसंरक्षण उपकरण)", "Breathing device carried to escape from smoke or gas.", "safety"),
    ("first_aid", "first aid", "प्राथमिक चिकित्सा", "প্রাথমিক চিকিৎসা", "ପ୍ରାଥମିକ ଚିକିତ୍ସା", "ప్రథమ చికిత్స", "प्रथमोपचार", "Immediate care given to an injured person.", "health"),
    ("accident", "accident", "दुर्घटना", "দুর্ঘটনা", "ଦୁର୍ଘଟଣା", "ప్రమాదం", "अपघात", "An event causing injury or damage.", "safety"),
    ("fatal_accident", "fatal accident", "घातक दुर्घटना", "প্রাণঘাতী দুর্ঘটনা", "ସାଂଘାତିକ ଦୁର୍ଘଟଣା", "ప్రాణాంతక ప్రమాదం", "प्राणघातक अपघात", "An accident in which a person dies.", "safety"),
    ("serious_injury", "serious injury", "गंभीर चोट", "গুরুতর আঘাত", "ଗୁରୁତର ଆଘାତ", "తీవ్ర గాయం", "गंभीर दुखापत", "An injury the law classes as serious.", "safety"),
    ("dangerous_occurrence", "dangerous occurrence", "खतरनाक घटना", "বিপজ্জনক ঘটনা", "ବିପଜ୍ଜନକ ଘଟଣା", "ప్రమాదకర సంఘటన", "धोकादायक घटना", "A notifiable event that could have caused injury.", "safety"),
    ("mine_fire", "mine fire", "खदान में आग", "খনিতে অগ্নিকাণ্ড", "ଖଣିରେ ଅଗ୍ନିକାଣ୍ଡ", "గనిలో అగ్ని ప్రమాదం", "खाणीतील आग", "Fire in the mine workings or in coal.", "safety"),
    ("mock_drill", "emergency drill (mock rehearsal)", "मॉक ड्रिल", "মক ড্রিল (মহড়া)", "ମକ୍ ଡ୍ରିଲ୍", "మాక్ డ్రిల్", "मॉक ड्रिल (सराव)", "Practice of the emergency response plan.", "safety"),
    ("inspection", "inspection", "निरीक्षण", "পরিদর্শন", "ନିରୀକ୍ଷଣ", "తనిఖీ", "तपासणी", "Examination of a mine for compliance and hazards.", "governance"),
    ("inspector", "inspector", "निरीक्षक", "পরিদর্শক", "ନିରୀକ୍ଷକ", "తనిఖీ అధికారి", "निरीक्षक", "Officer authorised to inspect mines.", "roles"),
    ("violation", "violation", "उल्लंघन", "লঙ্ঘন", "ଉଲ୍ଲଂଘନ", "ఉల్లంఘన", "उल्लंघन", "A breach of a legal or safety requirement.", "governance"),
    ("corrective_action", "corrective action", "सुधारात्मक कार्रवाई", "সংশোধনমূলক ব্যবস্থা", "ସଂଶୋଧନମୂଳକ ପଦକ୍ଷେପ", "దిద్దుబాటు చర్య", "सुधारात्मक कारवाई", "Work done to fix a violation, with proof.", "governance"),
    ("compliance", "compliance", "अनुपालन", "নিয়ম মেনে চলা", "ଅନୁପାଳନ", "నిబంధనల పాటింపు", "अनुपालन", "Meeting legal and safety requirements.", "governance"),
    ("grievance", "grievance", "शिकायत", "অভিযোগ", "ଅଭିଯୋଗ", "ఫిర్యాదు", "तक्रार", "A complaint raised by a worker or community member.", "governance"),
    ("risk_score", "risk score", "जोखिम स्कोर", "ঝুঁকি স্কোর", "ବିପଦ ସ୍କୋର", "ప్రమాద స్కోరు", "जोखीम गुण", "The app's summary score for a mine (a demo value in this prototype).", "governance"),
    ("production", "production", "उत्पादन", "উৎপাদন", "ଉତ୍ପାଦନ", "ఉత్పత్తి", "उत्पादन", "Coal output, usually in tonnes.", "production"),
    ("shift", "shift", "पाली", "শিফট (পালা)", "ପାଳି", "షిఫ్ట్", "पाळी", "A working period, e.g. shift A, B or C.", "production"),
    ("target", "target", "लक्ष्य", "লক্ষ্যমাত্রা", "ଲକ୍ଷ୍ୟ", "లక్ష్యం", "लक्ष्य", "Planned production for a period.", "production"),
    ("environmental_clearance", "environmental clearance", "पर्यावरणीय स्वीकृति", "পরিবেশ ছাড়পত্র", "ପରିବେଶ ଅନୁମୋଦନ", "పర్యావరణ అనుమతి", "पर्यावरण मंजुरी", "Government approval of a project's environmental impact.", "environment"),
    ("consent_to_operate", "consent to operate", "संचालन की सहमति", "পরিচালনার সম্মতি", "ପରିଚାଳନା ସମ୍ମତି", "నిర్వహణ సమ్మతి", "संचालन संमती", "Pollution control board permission to run a plant or mine.", "environment"),
    ("air_quality", "air quality", "वायु गुणवत्ता", "বায়ুর মান", "ବାୟୁ ଗୁଣବତ୍ତା", "గాలి నాణ్యత", "हवेची गुणवत्ता", "Levels of pollutants such as PM10, PM2.5, SO2 and NO2 in air.", "environment"),
    ("subsidence", "subsidence", "भू-धंसाव", "ভূমি অবনমন", "ଭୂମି ଧସା", "భూమి కుంగుబాటు", "जमीन खचणे", "Sinking of the ground above mine workings.", "environment"),
]


def main() -> int:
    ids = [t[0] for t in TERMS]
    if len(ids) != len(set(ids)):
        print("ERROR: duplicate term_id", file=sys.stderr)
        return 1
    rows = [{"term_id": t[0], "en": t[1], "hi": t[2], "bn": t[3], "or": t[4], "te": t[5], "mr": t[6],
             "definition_en": t[7], "domain": t[8], "reviewed": "false",
             "note": "Draft by Claude; needs review by a native speaker with mining vocabulary"} for t in TERMS]
    with OUT.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    print(f"Wrote reference/glossary.csv: {len(rows)} terms x 6 languages, all reviewed = false; "
          f"sha256 {sha256_file(OUT)[:16]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
