import { useState, useCallback, useRef, useEffect } from "react";
import { Upload, Shield, MapPin, Clock, FileText, Cpu, AlertTriangle,
         BarChart3, ChevronRight, X, Eye, Layers, Zap, Search,
         Download, RefreshCw, Database, Activity, Globe } from "lucide-react";

// ─── API Service ─────────────────────────────────────────────────────────────
const API_BASE = typeof window !== "undefined"
  ? (window.METAINTEL_API_URL || "http://localhost/api")
  : "http://localhost/api";

const api = {
  async upload(file, opts = {}) {
    const fd = new FormData();
    fd.append("image", file);
    Object.entries(opts).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(`${API_BASE}/images/upload`, { method: "POST", body: fd });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  },
  async getImage(id) {
    const r = await fetch(`${API_BASE}/images/${id}`);
    return r.json();
  },
  async getStatus(id) {
    const r = await fetch(`${API_BASE}/images/${id}/status`);
    return r.json();
  },
  async getDashboard() {
    const r = await fetch(`${API_BASE}/analytics/dashboard`);
    return r.json();
  },
  async exportJson(id) {
    const r = await fetch(`${API_BASE}/reports/export-json/${id}`);
    return r.json();
  },
};

// ─── Hooks ────────────────────────────────────────────────────────────────────
function usePolling(imageId, onComplete) {
  useEffect(() => {
    if (!imageId) return;
    const iv = setInterval(async () => {
      try {
        const { status } = await api.getStatus(imageId);
        if (status === "completed" || status === "failed") {
          clearInterval(iv);
          onComplete(status, imageId);
        }
      } catch {}
    }, 1500);
    return () => clearInterval(iv);
  }, [imageId]);
}

// ─── Colour palette ───────────────────────────────────────────────────────────
const C = {
  bg:       "#080c10",
  surface:  "#0d1117",
  card:     "#0f1923",
  border:   "#1c2a38",
  accent:   "#00d4ff",
  accentDim:"#00d4ff22",
  green:    "#00ff88",
  amber:    "#ffb800",
  red:      "#ff3b5c",
  purple:   "#b47aff",
  text:     "#c9d8e8",
  muted:    "#4a6178",
  white:    "#eaf4ff",
};

// ─── Verdict config ───────────────────────────────────────────────────────────
const verdictCfg = {
  authentic:        { color: C.green,  label: "Authentic",        icon: "✓" },
  likely_authentic: { color: C.green,  label: "Likely Authentic",  icon: "✓" },
  suspicious:       { color: C.amber,  label: "Suspicious",        icon: "⚠" },
  likely_tampered:  { color: C.red,    label: "Likely Tampered",   icon: "✗" },
  tampered:         { color: C.red,    label: "Tampered",          icon: "✗" },
  ai_generated:     { color: C.purple, label: "AI Generated",      icon: "⬡" },
  unknown:          { color: C.muted,  label: "Unknown",           icon: "?" },
};

// ─── Shared UI Components ─────────────────────────────────────────────────────
const Panel = ({ title, icon: Icon, children, accent, actions }) => (
  <div style={{
    background: C.card,
    border: `1px solid ${accent ? accent + "55" : C.border}`,
    borderRadius: 12,
    overflow: "hidden",
    boxShadow: accent ? `0 0 20px ${accent}15` : "none",
  }}>
    <div style={{
      display: "flex", alignItems: "center", justifyContent: "space-between",
      padding: "14px 18px",
      borderBottom: `1px solid ${C.border}`,
      background: `linear-gradient(90deg, ${C.surface}00, ${C.surface}88)`,
    }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
        {Icon && <Icon size={15} color={accent || C.accent} />}
        <span style={{ color: C.white, fontFamily: "'DM Mono', monospace", fontSize: 12,
                        letterSpacing: "0.12em", textTransform: "uppercase" }}>
          {title}
        </span>
      </div>
      {actions}
    </div>
    <div style={{ padding: 18 }}>{children}</div>
  </div>
);

const Stat = ({ label, value, color, sub }) => (
  <div style={{ textAlign: "center" }}>
    <div style={{ fontSize: 28, fontWeight: 700, color: color || C.accent,
                  fontFamily: "'DM Mono', monospace", lineHeight: 1 }}>
      {value ?? "—"}
    </div>
    {sub && <div style={{ fontSize: 10, color: C.muted, marginTop: 2 }}>{sub}</div>}
    <div style={{ fontSize: 11, color: C.muted, marginTop: 4, textTransform: "uppercase",
                  letterSpacing: "0.08em" }}>
      {label}
    </div>
  </div>
);

const Badge = ({ children, color }) => (
  <span style={{
    background: `${color || C.accent}22`,
    color: color || C.accent,
    border: `1px solid ${color || C.accent}44`,
    borderRadius: 4, padding: "2px 8px", fontSize: 10,
    fontFamily: "'DM Mono', monospace", letterSpacing: "0.08em",
  }}>
    {children}
  </span>
);

const ProgressBar = ({ value, color, label }) => (
  <div>
    {label && <div style={{ display:"flex", justifyContent:"space-between",
                             marginBottom: 4, fontSize: 11, color: C.muted }}>
      <span>{label}</span><span style={{ color: color || C.accent }}>{value}%</span>
    </div>}
    <div style={{ background: C.border, borderRadius: 3, height: 4 }}>
      <div style={{
        background: `linear-gradient(90deg, ${color || C.accent}, ${color || C.accent}88)`,
        width: `${Math.min(value, 100)}%`, height: "100%", borderRadius: 3,
        transition: "width 1s ease",
      }} />
    </div>
  </div>
);

const MetaRow = ({ label, value, flag }) => (
  value ? (
    <div style={{
      display: "flex", justifyContent: "space-between", alignItems: "flex-start",
      padding: "7px 0", borderBottom: `1px solid ${C.border}22`,
      gap: 12,
    }}>
      <span style={{ color: C.muted, fontSize: 11, flexShrink: 0,
                      fontFamily: "'DM Mono', monospace", width: 160 }}>
        {label}
      </span>
      <span style={{ color: flag ? C.amber : C.text, fontSize: 12,
                      textAlign: "right", wordBreak: "break-all" }}>
        {String(value)}
      </span>
    </div>
  ) : null
);

// ─── Upload Zone ──────────────────────────────────────────────────────────────
function UploadZone({ onUploaded }) {
  const [dragging, setDragging] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState(null);
  const inputRef = useRef();

  const handleFile = useCallback(async (file) => {
    if (!file) return;
    setUploading(true);
    setError(null);
    try {
      const result = await api.upload(file, { run_forensics: true, run_ai: true });
      onUploaded(result.image);
    } catch (e) {
      setError(e.message || "Upload failed");
    } finally {
      setUploading(false);
    }
  }, [onUploaded]);

  const onDrop = useCallback((e) => {
    e.preventDefault();
    setDragging(false);
    const f = e.dataTransfer?.files?.[0];
    if (f) handleFile(f);
  }, [handleFile]);

  return (
    <div
      onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
      onDragLeave={() => setDragging(false)}
      onDrop={onDrop}
      onClick={() => !uploading && inputRef.current?.click()}
      style={{
        border: `2px dashed ${dragging ? C.accent : C.border}`,
        borderRadius: 16,
        padding: "60px 40px",
        textAlign: "center",
        cursor: uploading ? "wait" : "pointer",
        transition: "all 0.25s ease",
        background: dragging ? C.accentDim : C.card,
        boxShadow: dragging ? `0 0 40px ${C.accent}22` : "none",
        position: "relative",
        overflow: "hidden",
      }}
    >
      {/* Animated grid bg */}
      <div style={{
        position: "absolute", inset: 0, opacity: 0.04,
        backgroundImage: `linear-gradient(${C.accent} 1px, transparent 1px),
                          linear-gradient(90deg, ${C.accent} 1px, transparent 1px)`,
        backgroundSize: "40px 40px",
      }} />

      <input ref={inputRef} type="file" accept="image/*,.tiff,.tif,.raw,.cr2,.nef,.arw,.dng"
             style={{ display: "none" }}
             onChange={(e) => handleFile(e.target.files?.[0])} />

      {uploading ? (
        <div>
          <div style={{
            width: 56, height: 56, borderRadius: "50%",
            border: `3px solid ${C.border}`, borderTopColor: C.accent,
            animation: "spin 0.8s linear infinite", margin: "0 auto 16px",
          }} />
          <p style={{ color: C.accent, fontFamily: "'DM Mono', monospace", fontSize: 14 }}>
            Uploading & queuing analysis…
          </p>
        </div>
      ) : (
        <>
          <div style={{
            width: 64, height: 64, borderRadius: "50%",
            background: `radial-gradient(circle, ${C.accent}22 0%, transparent 70%)`,
            border: `1px solid ${C.accent}44`,
            display: "flex", alignItems: "center", justifyContent: "center",
            margin: "0 auto 20px",
          }}>
            <Upload size={24} color={C.accent} />
          </div>
          <p style={{ color: C.white, fontSize: 16, marginBottom: 8,
                       fontFamily: "'Syne', sans-serif", fontWeight: 600 }}>
            Drop image for forensic analysis
          </p>
          <p style={{ color: C.muted, fontSize: 12 }}>
            JPEG · PNG · TIFF · RAW · DNG · CR2 · NEF · HEIC · WebP
          </p>
          {error && (
            <p style={{ color: C.red, fontSize: 12, marginTop: 12,
                         background: `${C.red}11`, padding: "6px 12px", borderRadius: 6 }}>
              {error}
            </p>
          )}
        </>
      )}
    </div>
  );
}

// ─── Metadata Tree Panel ──────────────────────────────────────────────────────
function MetadataPanel({ metadata, image }) {
  const [section, setSection] = useState("camera");

  const sections = {
    camera:      { label: "Camera",     icon: "📷" },
    capture:     { label: "Capture",    icon: "⚙️" },
    timestamps:  { label: "Timestamps", icon: "🕐" },
    iptc:        { label: "IPTC/XMP",   icon: "📝" },
    color_profile:{ label: "Color",     icon: "🎨" },
    standards:   { label: "Standards",  icon: "📋" },
    anomalies:   { label: `Anomalies${metadata?.anomalies?.length ? ` (${metadata.anomalies.length})` : ""}`,
                   icon: "⚠️" },
  };

  const data = metadata?.[section] || {};

  return (
    <Panel title="Metadata Intelligence" icon={Database}
           actions={
             <div style={{ display:"flex", gap: 6 }}>
               {["EXIF", "IPTC", "XMP"].map(s => (
                 <Badge key={s} color={metadata?.standards?.[s.toLowerCase()] ? C.green : C.muted}>
                   {s}
                 </Badge>
               ))}
             </div>
           }
    >
      {/* Section tabs */}
      <div style={{ display:"flex", gap: 4, marginBottom: 16, flexWrap: "wrap" }}>
        {Object.entries(sections).map(([key, cfg]) => (
          <button key={key} onClick={() => setSection(key)}
            style={{
              background: section === key ? `${C.accent}22` : "transparent",
              color: section === key ? C.accent : C.muted,
              border: `1px solid ${section === key ? C.accent + "44" : C.border}`,
              borderRadius: 6, padding: "4px 10px",
              fontSize: 11, cursor: "pointer",
              fontFamily: "'DM Mono', monospace",
            }}>
            {cfg.icon} {cfg.label}
          </button>
        ))}
      </div>

      {section === "anomalies" ? (
        <div>
          {!metadata?.anomalies?.length ? (
            <div style={{ color: C.green, fontSize: 13, textAlign: "center", padding: 24 }}>
              ✓ No metadata anomalies detected
            </div>
          ) : (
            metadata.anomalies.map((a, i) => (
              <div key={i} style={{
                background: `${a.severity === "high" ? C.red : C.amber}11`,
                border: `1px solid ${a.severity === "high" ? C.red : C.amber}33`,
                borderRadius: 8, padding: 12, marginBottom: 8,
              }}>
                <div style={{ display:"flex", alignItems:"center", gap: 8, marginBottom: 4 }}>
                  <Badge color={a.severity === "high" ? C.red : C.amber}>
                    {a.severity?.toUpperCase()}
                  </Badge>
                  <span style={{ color: C.white, fontSize: 12, fontWeight: 600 }}>
                    {a.type?.replace(/_/g, " ")}
                  </span>
                </div>
                <p style={{ color: C.text, fontSize: 12, margin: 0 }}>{a.description}</p>
                {a.fields && (
                  <div style={{ marginTop: 6, display:"flex", gap: 4, flexWrap:"wrap" }}>
                    {a.fields.map(f => <Badge key={f} color={C.muted}>{f}</Badge>)}
                  </div>
                )}
              </div>
            ))
          )}
        </div>
      ) : section === "standards" ? (
        <div style={{ display:"grid", gridTemplateColumns:"1fr 1fr", gap: 10 }}>
          {Object.entries(data).map(([k, v]) => (
            <div key={k} style={{
              display:"flex", alignItems:"center", justifyContent:"space-between",
              background: C.surface, borderRadius: 8, padding: "10px 14px",
              border: `1px solid ${v ? C.green + "44" : C.border}`,
            }}>
              <span style={{ color: C.text, fontSize: 12,
                              fontFamily:"'DM Mono', monospace" }}>{k}</span>
              <span style={{ color: v ? C.green : C.muted, fontSize: 18 }}>
                {v ? "●" : "○"}
              </span>
            </div>
          ))}
        </div>
      ) : (
        <div>
          {Object.entries(data).filter(([, v]) => v !== null && v !== undefined).length === 0 ? (
            <p style={{ color: C.muted, fontSize: 12, textAlign:"center", padding: 16 }}>
              No data in this category
            </p>
          ) : (
            Object.entries(data).map(([k, v]) =>
              v !== null && v !== undefined ? (
                <MetaRow key={k} label={k} value={
                  Array.isArray(v) ? v.join(", ") :
                  typeof v === "object" ? JSON.stringify(v) : v
                } />
              ) : null
            )
          )}
        </div>
      )}
    </Panel>
  );
}

// ─── Forensics Panel ──────────────────────────────────────────────────────────
function ForensicsPanel({ forensics }) {
  if (!forensics) return (
    <Panel title="Forensic Analysis" icon={Shield}>
      <div style={{ color: C.muted, fontSize: 12, textAlign:"center", padding: 24 }}>
        Awaiting forensic analysis…
      </div>
    </Panel>
  );

  const vc  = verdictCfg[forensics.authenticity_verdict] || verdictCfg.unknown;
  const score = forensics.authenticity_score ?? 0;

  return (
    <Panel title="Forensic Analysis" icon={Shield} accent={vc.color}>
      {/* Verdict hero */}
      <div style={{
        background: `linear-gradient(135deg, ${vc.color}11, ${vc.color}05)`,
        border: `1px solid ${vc.color}33`, borderRadius: 12,
        padding: "20px", marginBottom: 18, textAlign: "center",
      }}>
        <div style={{ fontSize: 36, marginBottom: 8 }}>{vc.icon}</div>
        <div style={{ color: vc.color, fontSize: 18, fontWeight: 700,
                       fontFamily:"'Syne', sans-serif", letterSpacing: "0.04em" }}>
          {vc.label}
        </div>
        <div style={{ color: C.muted, fontSize: 12, marginTop: 4 }}>
          Authenticity Score: <span style={{ color: vc.color }}>{score.toFixed(1)}%</span>
        </div>
      </div>

      {/* Analysis scores */}
      <div style={{ display:"flex", flexDirection:"column", gap: 12, marginBottom: 18 }}>
        {forensics.ela_performed && (
          <ProgressBar label="Error Level Analysis"
            value={forensics.ela_score || 0}
            color={forensics.ela_score > 40 ? C.red : C.green} />
        )}
        {forensics.noise_analysis_performed && (
          <ProgressBar label="Noise Inconsistency"
            value={forensics.noise_score || 0}
            color={forensics.noise_score > 40 ? C.amber : C.green} />
        )}
        {forensics.copy_move_confidence !== null && (
          <ProgressBar label="Copy-Move Detection"
            value={forensics.copy_move_confidence || 0}
            color={forensics.copy_move_detected ? C.red : C.green} />
        )}
        {forensics.ai_detection_performed && (
          <ProgressBar label="AI Generation Probability"
            value={forensics.ai_confidence || 0}
            color={forensics.is_ai_generated ? C.purple : C.green} />
        )}
      </div>

      {/* Stats grid */}
      <div style={{ display:"grid", gridTemplateColumns:"repeat(3,1fr)", gap: 12 }}>
        <Stat label="Faces" value={forensics.face_count}
              color={forensics.face_count > 0 ? C.accent : C.muted} />
        <Stat label="Regions" value={forensics.ela_regions?.length || 0}
              color={forensics.ela_regions?.length > 0 ? C.amber : C.green} />
        <Stat label="AI Score"
              value={forensics.ai_confidence ? `${forensics.ai_confidence.toFixed(0)}%` : "—"}
              color={forensics.is_ai_generated ? C.purple : C.green} />
      </div>

      {forensics.tampering_indicators?.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <p style={{ color: C.muted, fontSize: 11, marginBottom: 8,
                       textTransform:"uppercase", letterSpacing:"0.1em" }}>
            Tampering Indicators
          </p>
          <div style={{ display:"flex", gap: 6, flexWrap:"wrap" }}>
            {forensics.tampering_indicators.map(t => (
              <Badge key={t} color={C.red}>{t.replace(/_/g," ")}</Badge>
            ))}
          </div>
        </div>
      )}
    </Panel>
  );
}

// ─── Map Panel ────────────────────────────────────────────────────────────────
function MapPanel({ gpsData }) {
  if (!gpsData?.latitude) return (
    <Panel title="Geospatial Intelligence" icon={MapPin}>
      <div style={{ textAlign:"center", padding: 40, color: C.muted }}>
        <Globe size={32} style={{ opacity: 0.3, marginBottom: 12 }} />
        <p style={{ fontSize: 13 }}>No GPS data found in image</p>
      </div>
    </Panel>
  );

  const { latitude: lat, longitude: lon } = gpsData;
  const mapUrl = `https://www.openstreetmap.org/export/embed.html?bbox=${lon-0.02},${lat-0.02},${lon+0.02},${lat+0.02}&layer=mapnik&marker=${lat},${lon}`;

  return (
    <Panel title="Geospatial Intelligence" icon={MapPin} accent={C.green}>
      {/* Map embed */}
      <div style={{ borderRadius: 10, overflow:"hidden", marginBottom: 14,
                     border: `1px solid ${C.border}`, height: 220 }}>
        <iframe src={mapUrl} width="100%" height="100%" frameBorder="0"
                style={{ filter: "invert(0.9) hue-rotate(180deg)" }}
                title="GPS Location" />
      </div>

      {/* Location details */}
      <div style={{ display:"grid", gridTemplateColumns:"1fr 1fr", gap: 8, marginBottom: 12 }}>
        <div style={{ background: C.surface, borderRadius: 8, padding: 12,
                       border: `1px solid ${C.border}` }}>
          <div style={{ color: C.muted, fontSize: 10, marginBottom: 4,
                         textTransform:"uppercase", letterSpacing:"0.1em" }}>
            Latitude
          </div>
          <div style={{ color: C.accent, fontFamily:"'DM Mono', monospace", fontSize: 13 }}>
            {lat.toFixed(6)}°
          </div>
        </div>
        <div style={{ background: C.surface, borderRadius: 8, padding: 12,
                       border: `1px solid ${C.border}` }}>
          <div style={{ color: C.muted, fontSize: 10, marginBottom: 4,
                         textTransform:"uppercase", letterSpacing:"0.1em" }}>
            Longitude
          </div>
          <div style={{ color: C.accent, fontFamily:"'DM Mono', monospace", fontSize: 13 }}>
            {lon.toFixed(6)}°
          </div>
        </div>
      </div>

      {gpsData.city && (
        <div style={{ background: `${C.green}11`, border: `1px solid ${C.green}33`,
                       borderRadius: 8, padding: 12 }}>
          <div style={{ color: C.green, fontSize: 13, fontWeight: 600, marginBottom: 4 }}>
            📍 {[gpsData.city, gpsData.state, gpsData.country].filter(Boolean).join(", ")}
          </div>
          {gpsData.street && (
            <div style={{ color: C.muted, fontSize: 11 }}>{gpsData.street}</div>
          )}
          {gpsData.altitude && (
            <div style={{ color: C.muted, fontSize: 11, marginTop: 4 }}>
              Altitude: {gpsData.altitude.toFixed(0)}m
            </div>
          )}
        </div>
      )}

      <a href={`https://www.google.com/maps?q=${lat},${lon}`} target="_blank" rel="noreferrer"
         style={{ display:"block", textAlign:"center", marginTop: 12, color: C.accent,
                   fontSize: 11, textDecoration:"none" }}>
        Open in Google Maps →
      </a>
    </Panel>
  );
}

// ─── Timeline Panel ───────────────────────────────────────────────────────────
function TimelinePanel({ events }) {
  if (!events?.length) return (
    <Panel title="Activity Timeline" icon={Clock}>
      <p style={{ color: C.muted, fontSize: 12, textAlign:"center", padding: 24 }}>
        No timeline events available
      </p>
    </Panel>
  );

  const iconMap = { capture: "📷", edit: "✏️", upload: "⬆️", location: "📍" };
  const colorMap = { capture: C.accent, edit: C.amber, upload: C.green, location: C.purple };

  return (
    <Panel title="Activity Timeline" icon={Clock}>
      <div style={{ position:"relative" }}>
        {/* Vertical line */}
        <div style={{
          position:"absolute", left: 16, top: 8, bottom: 8,
          width: 1, background: C.border,
        }} />

        {events.map((event, i) => (
          <div key={i} style={{
            display:"flex", gap: 14, marginBottom: 16, position:"relative",
          }}>
            {/* Dot */}
            <div style={{
              width: 32, height: 32, borderRadius: "50%", flexShrink: 0, zIndex: 1,
              background: event.is_anomaly ? `${C.red}22` : `${colorMap[event.event_type] || C.accent}22`,
              border: `1px solid ${event.is_anomaly ? C.red : colorMap[event.event_type] || C.accent}`,
              display:"flex", alignItems:"center", justifyContent:"center",
              fontSize: 14,
            }}>
              {event.is_anomaly ? "⚠" : (iconMap[event.event_type] || "●")}
            </div>

            <div style={{ flex: 1, paddingTop: 4 }}>
              <div style={{ display:"flex", alignItems:"center", gap: 8, marginBottom: 3 }}>
                <span style={{
                  color: event.is_anomaly ? C.red : C.white,
                  fontSize: 12, fontWeight: 600,
                }}>
                  {event.description}
                </span>
                {event.is_anomaly && (
                  <Badge color={C.red}>ANOMALY</Badge>
                )}
              </div>
              <div style={{ color: C.muted, fontSize: 10,
                             fontFamily:"'DM Mono', monospace" }}>
                {event.event_time
                  ? new Date(event.event_time).toLocaleString()
                  : "Unknown time"}
                {event.event_source && ` · ${event.event_source.toUpperCase()}`}
              </div>
              {event.anomaly_reason && (
                <div style={{ color: C.amber, fontSize: 11, marginTop: 4 }}>
                  {event.anomaly_reason}
                </div>
              )}
            </div>
          </div>
        ))}
      </div>
    </Panel>
  );
}

// ─── Image Info Panel ─────────────────────────────────────────────────────────
function ImageInfoPanel({ image, imageData }) {
  const [exporting, setExporting] = useState(false);

  const handleExport = async () => {
    setExporting(true);
    try {
      const data = await api.exportJson(image.id);
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement("a");
      a.href = url; a.download = `metaintel-${image.id.slice(0,8)}.json`;
      a.click(); URL.revokeObjectURL(url);
    } catch {}
    setExporting(false);
  };

  const statusColor = {
    completed: C.green, failed: C.red, processing: C.accent,
    queued: C.amber, pending: C.muted,
  }[image.status] || C.muted;

  return (
    <Panel title="Image Record" icon={Eye}
           actions={
             <button onClick={handleExport} disabled={exporting}
               style={{
                 background:`${C.accent}22`, color: C.accent,
                 border:`1px solid ${C.accent}44`, borderRadius: 6,
                 padding:"4px 10px", fontSize: 11, cursor:"pointer",
                 display:"flex", alignItems:"center", gap: 5,
               }}>
               <Download size={12} /> JSON
             </button>
           }
    >
      <div style={{ display:"flex", gap: 14, alignItems:"flex-start", marginBottom: 16 }}>
        {/* Thumbnail */}
        <div style={{
          width: 80, height: 80, borderRadius: 10, overflow:"hidden",
          border: `1px solid ${C.border}`, flexShrink: 0,
          background: C.surface, display:"flex", alignItems:"center", justifyContent:"center",
        }}>
          {image.thumbnail_url
            ? <img src={image.thumbnail_url} alt="" style={{ width:"100%", height:"100%", objectFit:"cover" }} />
            : <Eye size={24} color={C.muted} />
          }
        </div>

        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{
            color: C.white, fontSize: 13, fontWeight: 600,
            whiteSpace:"nowrap", overflow:"hidden", textOverflow:"ellipsis",
            marginBottom: 6,
          }}>
            {image.original_filename}
          </div>
          <div style={{ display:"flex", gap: 6, flexWrap:"wrap", marginBottom: 8 }}>
            <Badge color={statusColor}>{image.status?.toUpperCase()}</Badge>
            <Badge color={C.muted}>{image.mime_type?.split("/")[1]?.toUpperCase()}</Badge>
            <Badge color={C.muted}>{image.formatted_size || "—"}</Badge>
          </div>
          <div style={{ color: C.muted, fontSize: 11, fontFamily:"'DM Mono', monospace" }}>
            {image.id?.slice(0, 16)}…
          </div>
        </div>
      </div>

      <MetaRow label="SHA-256" value={image.sha256_hash?.slice(0,32) + "…"} />
      <MetaRow label="MD5"     value={image.md5_hash} />
      <MetaRow label="Uploaded"
               value={image.created_at ? new Date(image.created_at).toLocaleString() : null} />
      {imageData?.metadata_tree?.camera?.["Camera Make"] && (
        <MetaRow label="Camera"
                 value={`${imageData.metadata_tree.camera["Camera Make"]} ${imageData.metadata_tree.camera["Camera Model"] || ""}`} />
      )}
      {imageData?.metadata_tree?.capture?.["Date/Time Original"] && (
        <MetaRow label="Captured"
                 value={imageData.metadata_tree.capture?.["Date/Time Original"]} />
      )}
    </Panel>
  );
}

// ─── Processing Indicator ─────────────────────────────────────────────────────
function ProcessingStatus({ status }) {
  const steps = [
    { key: "metadata",   label: "Metadata Extraction",    icon: Database },
    { key: "forensics",  label: "Forensic Analysis",       icon: Shield },
    { key: "geospatial", label: "Geospatial Processing",   icon: MapPin },
    { key: "indexing",   label: "Search Indexing",         icon: Search },
  ];

  return (
    <div style={{
      background: C.card, border: `1px solid ${C.border}`,
      borderRadius: 12, padding: 24,
    }}>
      <div style={{ textAlign:"center", marginBottom: 24 }}>
        <div style={{
          width: 48, height: 48, borderRadius:"50%",
          border: `3px solid ${C.border}`, borderTopColor: C.accent,
          animation: "spin 1s linear infinite", margin: "0 auto 12px",
        }} />
        <p style={{ color: C.accent, fontFamily:"'DM Mono', monospace",
                     fontSize: 13, textTransform:"uppercase", letterSpacing:"0.1em" }}>
          Analyzing image…
        </p>
        <p style={{ color: C.muted, fontSize: 11, marginTop: 4 }}>
          Status: <span style={{ color: C.white }}>{status}</span>
        </p>
      </div>

      <div style={{ display:"flex", flexDirection:"column", gap: 8 }}>
        {steps.map(({ key, label, icon: Icon }, i) => (
          <div key={key} style={{
            display:"flex", alignItems:"center", gap: 12,
            background: C.surface, borderRadius: 8, padding: "10px 14px",
            border: `1px solid ${C.border}`,
            opacity: 0.5 + (i * 0.15),
          }}>
            <Icon size={14} color={C.accent} style={{ opacity: 0.7 }} />
            <span style={{ color: C.text, fontSize: 12 }}>{label}</span>
            <div style={{ marginLeft:"auto", display:"flex", gap: 3 }}>
              {[0,1,2].map(j => (
                <div key={j} style={{
                  width: 4, height: 4, borderRadius:"50%",
                  background: C.accent,
                  animation: `pulse 1.5s ease-in-out ${j * 0.3}s infinite`,
                }} />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Dashboard Stats ──────────────────────────────────────────────────────────
function DashboardStats({ stats }) {
  if (!stats) return null;
  const t = stats.totals || {};
  return (
    <div style={{ display:"grid", gridTemplateColumns:"repeat(6,1fr)", gap: 10, marginBottom: 24 }}>
      {[
        { label:"Total Images",  value: t.images,          color: C.accent },
        { label:"Today",         value: t.processed_today, color: C.green  },
        { label:"With GPS",      value: t.with_gps,        color: C.purple },
        { label:"Anomalies",     value: t.anomalies,       color: C.amber  },
        { label:"AI Generated",  value: t.ai_generated,    color: C.purple },
        { label:"Tampered",      value: t.tampered,        color: C.red    },
      ].map(({ label, value, color }) => (
        <div key={label} style={{
          background: C.card, border: `1px solid ${C.border}`,
          borderRadius: 10, padding: "16px 12px", textAlign:"center",
          borderTop: `2px solid ${color}55`,
        }}>
          <Stat label={label} value={value} color={color} />
        </div>
      ))}
    </div>
  );
}

// ─── Main App ─────────────────────────────────────────────────────────────────
export default function MetaIntelDashboard() {
  const [mode, setMode]           = useState("home"); // home | inspect | processing
  const [currentImage, setCurrentImage] = useState(null);
  const [imageData, setImageData] = useState(null);
  const [processingId, setProcessingId] = useState(null);
  const [processingStatus, setProcessingStatus] = useState(null);
  const [stats, setStats]         = useState(null);
  const [viewMode, setViewMode]   = useState("inspector"); // inspector | investigation

  // Load dashboard stats on mount
  useEffect(() => {
    api.getDashboard().then(setStats).catch(() => {});
  }, []);

  // Poll for processing completion
  usePolling(processingId, async (status, id) => {
    setProcessingId(null);
    if (status === "completed") {
      const data = await api.getImage(id);
      setImageData(data);
      setCurrentImage(data.image);
      setMode("inspect");
    } else {
      setProcessingStatus("failed");
      setMode("home");
    }
  });

  const handleUploaded = (image) => {
    setCurrentImage(image);
    setProcessingId(image.id);
    setProcessingStatus(image.status);
    setMode("processing");
  };

  const reset = () => {
    setMode("home");
    setCurrentImage(null);
    setImageData(null);
    setProcessingId(null);
  };

  return (
    <div style={{
      minHeight: "100vh",
      background: C.bg,
      color: C.text,
      fontFamily: "'Inter', system-ui, sans-serif",
    }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @keyframes spin    { to { transform: rotate(360deg); } }
        @keyframes pulse   { 0%,100% { opacity:.2; transform:scale(.8) } 50% { opacity:1; transform:scale(1) } }
        @keyframes fadeIn  { from { opacity:0; transform:translateY(8px) } to { opacity:1; transform:translateY(0) } }
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #1c2a38; border-radius: 2px; }
      `}</style>

      {/* ── Top Navigation ── */}
      <nav style={{
        background: `${C.surface}cc`,
        borderBottom: `1px solid ${C.border}`,
        backdropFilter: "blur(12px)",
        padding: "0 28px",
        display: "flex", alignItems: "center", gap: 20,
        height: 54, position: "sticky", top: 0, zIndex: 100,
      }}>
        {/* Logo */}
        <div style={{ display:"flex", alignItems:"center", gap: 10, cursor:"pointer" }}
             onClick={reset}>
          <div style={{
            width: 28, height: 28, borderRadius: 6,
            background: `linear-gradient(135deg, ${C.accent}, ${C.purple})`,
            display:"flex", alignItems:"center", justifyContent:"center",
          }}>
            <Shield size={14} color="#000" />
          </div>
          <span style={{
            fontFamily:"'Syne', sans-serif", fontWeight: 800, fontSize: 16,
            background: `linear-gradient(90deg, ${C.white}, ${C.accent})`,
            WebkitBackgroundClip:"text", WebkitTextFillColor:"transparent",
          }}>
            MetaIntel
          </span>
          <Badge color={C.purple}>FORENSICS</Badge>
        </div>

        <div style={{ flex: 1 }} />

        {/* Mode toggle (when inspecting) */}
        {mode === "inspect" && (
          <div style={{
            display:"flex", background: C.card,
            border: `1px solid ${C.border}`, borderRadius: 8, padding: 3,
          }}>
            {[
              { id:"inspector",    label:"Inspector",    icon: Eye     },
              { id:"investigation",label:"Investigation", icon: Layers  },
            ].map(({ id, label, icon: Icon }) => (
              <button key={id} onClick={() => setViewMode(id)}
                style={{
                  background: viewMode === id ? `${C.accent}22` : "transparent",
                  color: viewMode === id ? C.accent : C.muted,
                  border: viewMode === id ? `1px solid ${C.accent}33` : "1px solid transparent",
                  borderRadius: 6, padding: "5px 12px",
                  fontSize: 11, cursor:"pointer",
                  display:"flex", alignItems:"center", gap: 5,
                  fontFamily:"'DM Mono', monospace",
                }}>
                <Icon size={12} /> {label}
              </button>
            ))}
          </div>
        )}

        {currentImage && (
          <button onClick={reset} style={{
            background:"transparent", color: C.muted,
            border:"none", cursor:"pointer",
            display:"flex", alignItems:"center", gap: 4, fontSize: 12,
          }}>
            <X size={14} /> Reset
          </button>
        )}
      </nav>

      {/* ── Main Content ── */}
      <main style={{ maxWidth: 1400, margin: "0 auto", padding: "28px 24px" }}>

        {/* Home / Upload */}
        {mode === "home" && (
          <div style={{ animation: "fadeIn 0.4s ease" }}>
            {/* Hero */}
            <div style={{ textAlign:"center", marginBottom: 48, paddingTop: 24 }}>
              <div style={{
                display:"inline-flex", alignItems:"center", gap: 8,
                background: `${C.accent}11`, border: `1px solid ${C.accent}33`,
                borderRadius: 20, padding: "5px 14px", marginBottom: 20,
                fontSize: 11, color: C.accent, fontFamily:"'DM Mono', monospace",
              }}>
                <Zap size={11} /> AI-Powered Image Forensics Platform
              </div>
              <h1 style={{
                fontFamily:"'Syne', sans-serif", fontSize: "clamp(32px, 5vw, 56px)",
                fontWeight: 800, color: C.white, marginBottom: 16, lineHeight: 1.1,
              }}>
                Metadata Intelligence{" "}
                <span style={{
                  background: `linear-gradient(90deg, ${C.accent}, ${C.purple})`,
                  WebkitBackgroundClip:"text", WebkitTextFillColor:"transparent",
                }}>
                  & Digital Forensics
                </span>
              </h1>
              <p style={{ color: C.muted, fontSize: 16, maxWidth: 560, margin: "0 auto" }}>
                Extract deep metadata, detect tampering, analyze GPS trails,
                and identify AI-generated images with military-grade forensics.
              </p>
            </div>

            <DashboardStats stats={stats} />

            <div style={{ maxWidth: 640, margin: "0 auto" }}>
              <UploadZone onUploaded={handleUploaded} />

              {/* Feature pills */}
              <div style={{
                display:"flex", gap: 8, flexWrap:"wrap", justifyContent:"center",
                marginTop: 24,
              }}>
                {[
                  { icon: Database, label: "EXIF · IPTC · XMP · ICC" },
                  { icon: Shield,   label: "ELA · Copy-Move · AI Detection" },
                  { icon: MapPin,   label: "GPS · Reverse Geocoding" },
                  { icon: Clock,    label: "Timeline Analysis" },
                  { icon: Activity, label: "Anomaly Detection" },
                  { icon: FileText, label: "PDF Forensic Reports" },
                ].map(({ icon: Icon, label }) => (
                  <div key={label} style={{
                    display:"flex", alignItems:"center", gap: 6,
                    background: C.card, border: `1px solid ${C.border}`,
                    borderRadius: 20, padding: "6px 14px",
                    fontSize: 11, color: C.muted,
                  }}>
                    <Icon size={11} color={C.accent} /> {label}
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        {/* Processing */}
        {mode === "processing" && (
          <div style={{ maxWidth: 540, margin: "60px auto", animation: "fadeIn 0.4s ease" }}>
            <ProcessingStatus status={processingStatus} />
          </div>
        )}

        {/* Inspect / Analyze */}
        {mode === "inspect" && imageData && (
          <div style={{ animation: "fadeIn 0.3s ease" }}>

            {/* Inspector mode: raw data view */}
            {viewMode === "inspector" && (
              <div style={{ display:"grid", gridTemplateColumns:"1fr 1fr", gap: 16 }}>
                <div style={{ display:"flex", flexDirection:"column", gap: 16 }}>
                  <ImageInfoPanel image={currentImage} imageData={imageData} />
                  <MetadataPanel
                    metadata={imageData.metadata_tree}
                    image={currentImage}
                  />
                </div>
                <div style={{ display:"flex", flexDirection:"column", gap: 16 }}>
                  <ForensicsPanel forensics={imageData.image?.forensicAnalysis} />
                  <MapPanel gpsData={imageData.image?.gpsData} />
                  <TimelinePanel events={imageData.image?.timelineEvents} />
                </div>
              </div>
            )}

            {/* Investigation mode: report view */}
            {viewMode === "investigation" && (
              <div style={{ display:"flex", flexDirection:"column", gap: 16 }}>
                {/* Summary banner */}
                {(() => {
                  const fa = imageData.image?.forensicAnalysis;
                  const vc = verdictCfg[fa?.authenticity_verdict] || verdictCfg.unknown;
                  const anomalyCount = imageData.metadata_tree?.anomalies?.length || 0;
                  return (
                    <div style={{
                      background: `linear-gradient(135deg, ${vc.color}15, ${vc.color}05)`,
                      border: `1px solid ${vc.color}44`, borderRadius: 16,
                      padding: "24px 28px",
                      display:"grid", gridTemplateColumns:"auto 1fr auto", gap: 24,
                      alignItems:"center",
                    }}>
                      <div style={{
                        width: 64, height: 64, borderRadius: "50%",
                        background: `${vc.color}22`, border: `2px solid ${vc.color}55`,
                        display:"flex", alignItems:"center", justifyContent:"center",
                        fontSize: 28,
                      }}>{vc.icon}</div>
                      <div>
                        <div style={{ color: vc.color, fontSize: 20,
                                       fontFamily:"'Syne', sans-serif", fontWeight: 700 }}>
                          {vc.label}
                        </div>
                        <div style={{ color: C.muted, fontSize: 13, marginTop: 4 }}>
                          {currentImage.original_filename} · {currentImage.formatted_size}
                          {anomalyCount > 0 && ` · ${anomalyCount} anomaly${anomalyCount > 1 ? "ies" : "y"} detected`}
                        </div>
                      </div>
                      <div style={{ display:"grid", gridTemplateColumns:"1fr 1fr", gap: 12 }}>
                        <Stat label="Auth Score"
                              value={fa?.authenticity_score ? `${fa.authenticity_score.toFixed(0)}%` : "—"}
                              color={vc.color} />
                        <Stat label="Anomalies" value={anomalyCount}
                              color={anomalyCount > 0 ? C.amber : C.green} />
                      </div>
                    </div>
                  );
                })()}

                <div style={{ display:"grid", gridTemplateColumns:"1fr 1fr 1fr", gap: 16 }}>
                  <ForensicsPanel forensics={imageData.image?.forensicAnalysis} />
                  <MapPanel gpsData={imageData.image?.gpsData} />
                  <TimelinePanel events={imageData.image?.timelineEvents} />
                </div>
                <MetadataPanel metadata={imageData.metadata_tree} image={currentImage} />
              </div>
            )}
          </div>
        )}
      </main>
    </div>
  );
}
