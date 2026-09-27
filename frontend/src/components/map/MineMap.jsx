// The mines on a map (Phase 5B). Works with no internet: state and district outlines are local data
// from the API (DataMeet, via the data track), drawn as vector layers; Leaflet itself is bundled.
// An OpenStreetMap basemap can be switched on - off by default, attributed as OSM requires, loaded
// only while shown, never prefetched (OSM tile usage policy); if tiles fail, the outlines remain.
// Mines are circles at their real coordinates, coloured by risk band - and the band is also in the
// label and the tooltip, never colour alone; the location quality is shown too.
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { useEffect, useRef, useState } from "react";
import { useT } from "../../i18n/t";
import { token } from "../../utils/tokens";

const INDIA = [[6.5, 68], [36, 97.5]];
const OSM_URL = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a> contributors';
const BAND_TOKEN = { high: "--risk-high-dot", medium: "--risk-medium-dot", low: "--risk-low-dot" };
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

export function MineMap({ mines, states, districts, onOpen, height = 560 }) {
  const t = useT();
  const box = useRef(null);
  const map = useRef(null);
  const layers = useRef({});
  const [basemap, setBasemap] = useState(false);
  const [tilesFailed, setTilesFailed] = useState(false);

  // The map and its attribution, once.
  useEffect(() => {
    const m = L.map(box.current, { zoomSnap: 0.5, minZoom: 4, maxZoom: 19, preferCanvas: false, attributionControl: true });
    m.fitBounds(INDIA);
    m.attributionControl.addAttribution(esc(t("map.attributionGem")));
    m.attributionControl.addAttribution(esc(t("map.attributionDatameet")));
    map.current = m;
    // Layers belong to this map instance: a remount (StrictMode, tab switch) starts clean and fits again.
    return () => { m.remove(); map.current = null; layers.current = {}; };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Outlines (local data): the state fill at the bottom, district lines over it.
  useEffect(() => {
    const m = map.current;
    if (!m) return;
    const line = token("--muted", "#6b7280");
    for (const [key, data, style] of [
      ["districts", districts, { className: "district-outline", color: line, weight: 0.7, dashArray: "3 3", fill: false }],
      ["states", states, { className: "state-outline", color: line, weight: 1.2, fill: true, fillColor: token("--card", "#fff"), fillOpacity: 0.35 }],
    ]) {
      layers.current[key]?.remove();
      if (data) {
        layers.current[key] = L.geoJSON(data, { style, interactive: key === "states" })
          .bindTooltip((l) => esc(l.feature.properties.state ?? l.feature.properties.district_2011 ?? ""), { sticky: true, opacity: 0.8 })
          .addTo(m);
        layers.current[key].bringToBack();
      }
    }
  }, [states, districts]);

  // Mines (polled with the screen).
  useEffect(() => {
    const m = map.current;
    if (!m || !mines) return;
    layers.current.mines?.remove();
    const group = L.featureGroup();
    for (const f of mines.features ?? []) {
      const [lon, lat] = f.geometry.coordinates;
      const p = f.properties;
      const band = t(`risk.${p.risk_level}`, { defaultValue: p.risk_level });
      const quality = t(`map.quality.${p.location_quality}`, { defaultValue: p.location_quality ?? "-" });
      const exact = p.location_quality === "exact_gem";
      const marker = L.circleMarker([lat, lon], {
        className: `mine-marker risk-${p.risk_level}`, radius: mines.features.length === 1 ? 11 : 7, weight: 2, color: "#ffffff", dashArray: exact ? null : "3 2",
        fillColor: token(BAND_TOKEN[p.risk_level] ?? "--muted", "#888"), fillOpacity: 0.95,
      });
      marker.bindTooltip(
        `<strong>${esc(p.name)}</strong> <span>${esc(p.code)}</span><br>${esc(band)} · ${esc(t("map.score", { score: p.score }))}`
          + `<br>${esc(t("map.openAlerts", { count: p.open_alerts ?? 0 }))}<br>${esc(p.district ?? "")}, ${esc(p.state ?? "")}<br><em>${esc(t("map.qualityLabel", { quality }))}</em>`,
        { direction: "top", permanent: mines.features.length === 1 },
      );
      marker.on("click", () => onOpen?.(p));
      marker.on("add", () => marker.getElement()?.setAttribute("data-mine", p.code));
      group.addLayer(marker);
    }
    group.addTo(m);
    layers.current.mines = group;
    if ((mines.features ?? []).length === 1) {
      const [lon, lat] = mines.features[0].geometry.coordinates;
      m.setView([lat, lon], 9);
    } else if (mines.features?.length && !layers.current.fitted) {
      m.fitBounds(group.getBounds().pad(0.25));
      layers.current.fitted = true;
    }
  }, [mines]); // eslint-disable-line react-hooks/exhaustive-deps

  // The optional online basemap.
  useEffect(() => {
    const m = map.current;
    if (!m) return;
    layers.current.tiles?.remove();
    layers.current.tiles = null;
    setTilesFailed(false);
    if (basemap) {
      const tiles = L.tileLayer(OSM_URL, { maxZoom: 19, attribution: OSM_ATTRIBUTION, crossOrigin: false });
      tiles.on("tileerror", () => setTilesFailed(true));
      tiles.addTo(m).bringToBack();
      layers.current.tiles = tiles;
    }
  }, [basemap]);

  return (
    <div className="stack tight">
      <div className="row wrap-row map-controls">
        <label className="check-row" htmlFor="map-basemap">
          <input id="map-basemap" type="checkbox" checked={basemap} onChange={(e) => setBasemap(e.target.checked)} />
          {t("map.basemap")}
        </label>
        {!basemap && <span className="faint small">{t("map.basemapOff")}</span>}
        {basemap && tilesFailed && <span className="notice small" id="map-tiles-failed">{t("map.offline")}</span>}
      </div>
      <div ref={box} className="mine-map" style={{ height }} id="mine-map" />
      <div className="row wrap-row map-legend">
        <span className="label">{t("map.legend")}</span>
        {["high", "medium", "low"].map((b) => (
          <span key={b} className="row"><span className="legend-dot" style={{ background: token(BAND_TOKEN[b], "#888") }} />{t(`risk.${b}`)}</span>
        ))}
        <span className="faint small">{t("map.mines", { count: mines?.features?.length ?? 0 })}</span>
      </div>
    </div>
  );
}
