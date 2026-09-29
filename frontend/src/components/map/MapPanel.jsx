// The map tab for every role: outlines fetched once (local data), the mines in scope polled with
// the screen (one request per cycle: GET /v1/views/map). Scope is the API's - a mine head gets
// their mine, corporate its companies' mines. A click opens the mine (for a mine head: their
// overview tab).
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getDistricts, getMapView, getStates } from "../../api/geo";
import { usePolling } from "../../hooks/usePolling";
import { useTranslation } from "react-i18next";
import { ErrorNotice } from "../common/ErrorNotice";
import { Loader } from "../common/Loader";
import { StateFilter } from "../common/StateFilter";
import { MineMap } from "./MineMap";

export function MapPanel({ state = null, states, onStateChange, onOpenMine = null }) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [outlines, setOutlines] = useState({ states: null, districts: null, error: null });
  const { data, error, loading } = usePolling(() => getMapView(state), { deps: [state] });

  useEffect(() => {
    let live = true;
    Promise.all([getStates(), getDistricts()])
      .then(([s, d]) => live && setOutlines({ states: s, districts: d, error: null }))
      .catch((e) => live && setOutlines((o) => ({ ...o, error: e })));
    return () => { live = false; };
  }, []);

  if (loading && !data) return <Loader label={t("map.loading")} />;
  return (
    <section className="panel-block" id="map-panel">
      <div className="panel-head wrap">
        <div>
          <h2>{t("map.title")}</h2>
          <span className="hint">{t("map.hint")}</span>
        </div>
        {onStateChange && (
          <div className="row head-controls">
            <StateFilter states={states} value={state} onChange={onStateChange} id="map-state-filter" />
          </div>
        )}
      </div>
      <div className="panel-body">
        <ErrorNotice error={error ?? outlines.error} />
        {/* Keyed by language: the map's own controls, credits and tooltips are built when it mounts. */}
        <MineMap key={i18n.language} mines={data?.mines} states={outlines.states} districts={outlines.districts}
                 onOpen={(p) => (onOpenMine ? onOpenMine(p) : navigate(`/gov/mines/${p.id}`))} />
        <p className="note">{t("map.qualityNote")}</p>
      </div>
    </section>
  );
}
