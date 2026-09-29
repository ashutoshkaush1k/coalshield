// One statutory obligation from the register (opened from the search panel): its title, domain,
// frequency, who is responsible and the citation. Read from GET /v1/obligations (the catalogue).
import { useEffect, useState } from "react";
import { getObligations } from "../../api/obligations";
import { frequencyLabel, obligationTitle } from "../../i18n/labels";
import { useT } from "../../i18n/t";
import { Loader } from "../common/Loader";
import { EmptyState } from "../common/EmptyState";
import { Drawer } from "../overlay/Overlay";
import { Citation } from "./Citation";

export function ObligationDrawer({ code, onClose }) {
  const t = useT();
  const [obligation, setObligation] = useState(undefined);
  useEffect(() => {
    let live = true;
    getObligations().then((all) => live && setObligation(all.find((o) => o.code === code) ?? null)).catch(() => live && setObligation(null));
    return () => { live = false; };
  }, [code]);

  return (
    <Drawer open onClose={onClose} title={code} subtitle={obligation ? obligationTitle(obligation) : ""}>
      {obligation === undefined ? <Loader /> : !obligation ? <EmptyState>{t("search.obligationMissing")}</EmptyState> : (
        <div className="stack tight" id="obligation-drawer">
          <div className="detail-field"><span className="label">{t("search.obligationDomain")}</span>
            <div className="detail-value">{t(`obligation.domain.${obligation.domain}`, { defaultValue: obligation.domain })}</div></div>
          <div className="detail-field"><span className="label">{t("search.obligationFrequency")}</span>
            <div className="detail-value">{frequencyLabel(obligation.frequency)}</div></div>
          <div className="detail-field"><span className="label">{t("search.obligationCitation")}</span>
            <div className="detail-value"><Citation obligation={obligation} /></div></div>
        </div>
      )}
    </Drawer>
  );
}
