import React, { useState, useCallback, useRef } from 'react';
import AppLayout from '../components/AppLayout';
import GeographyFilters from '../components/GeographyFilters';
import { Upload, FileSpreadsheet, CheckCircle, AlertCircle, X, Info, ChevronDown, ChevronUp } from 'lucide-react';
import api from '../lib/api';
import { getEmail } from '../lib/auth';

const FIELD_OPTIONS = [
  { value: '', label: '-- Skip --' },
  { value: 'serial_no',     label: 'Serial No' },
  { value: 'house_no',      label: 'House Number' },
  { value: 'voter_name',    label: 'Voter Name' },
  { value: 'relation_type', label: 'Relation Type (S/o, W/o, D/o)' },
  { value: 'relative_name', label: 'Relative Name' },
  { value: 'gender',        label: 'Gender' },
  { value: 'age',           label: 'Age' },
  { value: 'voter_id',      label: 'EPIC / Voter ID' },
  { value: 'part_no',       label: 'Part Number' },
  { value: 'roll_page_no',  label: 'Roll Page No' },
  { value: 'pdf_page',      label: 'PDF Page No' },
];

export default function ExcelImportPage() {
  const [file,         setFile]         = useState(null);
  const [preview,      setPreview]      = useState(null); // first 5 rows
  const [headers,      setHeaders]      = useState([]);   // raw header row
  const [columnMap,    setColumnMap]    = useState({});
  const [geoFilters,   setGeoFilters]   = useState({});
  const [skipFirst,    setSkipFirst]    = useState(true);
  const [importing,    setImporting]    = useState(false);
  const [importResult, setImportResult] = useState(null);
  const [error,        setError]        = useState('');
  const [showMapping,  setShowMapping]  = useState(false);
  const fileRef = useRef();

  const handleFileChange = (e) => {
    const f = e.target.files[0];
    if (!f) return;
    setFile(f);
    setImportResult(null);
    setError('');

    // Quick CSV preview if CSV
    if (f.name.endsWith('.csv')) {
      const reader = new FileReader();
      reader.onload = (ev) => {
        const lines = ev.target.result.split('\n').slice(0, 6).map(l => l.split(',').map(c => c.trim()));
        setHeaders(lines[0] || []);
        setPreview(lines.slice(1, 6));
        // Auto-build column map from first row
        const autoMap = {};
        (lines[0] || []).forEach((h, i) => {
          const opt = FIELD_OPTIONS.find(o => o.label.toLowerCase() === h.toLowerCase() || o.value === h.toLowerCase().replace(/\s/g, '_'));
          if (opt && opt.value) autoMap[i] = opt.value;
        });
        setColumnMap(autoMap);
        setShowMapping(true);
      };
      reader.readAsText(f);
    } else {
      setHeaders([]);
      setPreview(null);
      setShowMapping(true);
    }
  };

  const handleImport = async () => {
    if (!file) { setError('Please select a file.'); return; }
    setImporting(true); setError(''); setImportResult(null);

    const formData = new FormData();
    formData.append('file', file);
    formData.append('column_map', JSON.stringify(columnMap));
    formData.append('skip_first_row', skipFirst ? '1' : '0');
    if (geoFilters.district_id)        formData.append('district_id',        geoFilters.district_id);
    if (geoFilters.city_id)            formData.append('city_id',            geoFilters.city_id);
    if (geoFilters.assembly_id)        formData.append('assembly_id',        geoFilters.assembly_id);
    if (geoFilters.polling_station_id) formData.append('polling_station_id', geoFilters.polling_station_id);

    try {
      const res = await api.post('/admin/upload-excel', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
      setImportResult(res.data);
    } catch (err) {
      setError(err.response?.data?.error || err.response?.data?.message || 'Import failed.');
    } finally {
      setImporting(false);
    }
  };

  return (
    <AppLayout title="Excel Import">
      {/* Banner */}
      <div className="page-banner blue">
        <div className="banner-content">
          <div className="banner-title">Import Excel / CSV Voter Data</div>
          <div className="banner-subtitle">
            Upload voter records from Excel or CSV files. Map columns, assign district metadata, and import in bulk.
          </div>
        </div>
        <FileSpreadsheet size={80} className="banner-icon" />
      </div>

      {/* ── File Picker ─────────────────────────────────────────────────────── */}
      <div className="card" style={{ marginBottom: 16, padding: 24 }}>
        <div className="card-title" style={{ marginBottom: 16 }}>Step 1: Select File</div>

        <div
          className="dropzone"
          style={{ cursor: 'pointer' }}
          onClick={() => fileRef.current?.click()}
        >
          <input
            ref={fileRef}
            type="file"
            id="excel-file-input"
            accept=".xlsx,.xls,.csv"
            onChange={handleFileChange}
            style={{ display: 'none' }}
          />
          <div className="dropzone-icon"><FileSpreadsheet size={28} /></div>
          <div className="dropzone-title">
            {file ? file.name : 'Click to select Excel or CSV file'}
          </div>
          <div className="dropzone-sub">
            {file ? `${(file.size / 1024).toFixed(1)} KB` : 'Supports .xlsx, .xls, .csv • Max 100MB'}
          </div>
        </div>

        {file && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={skipFirst}
                onChange={e => setSkipFirst(e.target.checked)}
              />
              Skip first row (header row)
            </label>
          </div>
        )}
      </div>

      {/* ── Column Mapping ──────────────────────────────────────────────────── */}
      {file && (
        <div className="card" style={{ marginBottom: 16, padding: 24 }}>
          <div
            style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', cursor: 'pointer', marginBottom: 8 }}
            onClick={() => setShowMapping(v => !v)}
          >
            <div className="card-title">Step 2: Column Mapping</div>
            {showMapping ? <ChevronUp size={18} /> : <ChevronDown size={18} />}
          </div>

          {showMapping && (
            <>
              <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 16 }}>
                Map each column number (0 = first column) to a voter record field.
                Leave as "Skip" if the column is not needed.
              </p>

              {/* Preview table */}
              {preview && (
                <div className="table-wrap" style={{ marginBottom: 16 }}>
                  <table>
                    <thead>
                      <tr>
                        <th>Col #</th>
                        {headers.map((h, i) => <th key={i}>{h || `Col ${i}`}</th>)}
                      </tr>
                    </thead>
                    <tbody>
                      {preview.map((row, ri) => (
                        <tr key={ri}>
                          <td style={{ color: 'var(--muted)' }}>Row {ri + 2}</td>
                          {row.map((cell, ci) => <td key={ci}>{cell}</td>)}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 12 }}>
                {(headers.length ? headers : Array.from({ length: 10 }, (_, i) => `Column ${i}`)).map((h, i) => (
                  <div key={i}>
                    <label style={{ fontSize: 12, color: 'var(--muted)', display: 'block', marginBottom: 4 }}>
                      Col {i}: {headers[i] || ''}
                    </label>
                    <div className="geo-select-wrap">
                      <select
                        className="geo-select"
                        value={columnMap[i] || ''}
                        onChange={e => setColumnMap(prev => ({ ...prev, [i]: e.target.value }))}
                      >
                        {FIELD_OPTIONS.map(o => (
                          <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                      </select>
                      <ChevronDown size={14} className="geo-select-icon" />
                    </div>
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      )}

      {/* ── Geography Metadata ──────────────────────────────────────────────── */}
      {file && (
        <div className="card" style={{ marginBottom: 16, padding: 24 }}>
          <div className="card-title" style={{ marginBottom: 12 }}>Step 3: Location Metadata</div>
          <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
            All imported records will be tagged with this location.
          </p>
          <GeographyFilters value={geoFilters} onChange={setGeoFilters} scope="district" />
        </div>
      )}

      {/* ── Import Button ────────────────────────────────────────────────────── */}
      {file && (
        <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 24 }}>
          <button
            id="excel-import-btn"
            className="btn btn-primary"
            onClick={handleImport}
            disabled={importing}
          >
            {importing
              ? <><div className="spinner" style={{ width: 14, height: 14 }} /> Importing...</>
              : <><Upload size={16} /> Start Import</>}
          </button>
          <button className="btn btn-secondary" onClick={() => { setFile(null); setPreview(null); setHeaders([]); setColumnMap({}); }}>
            <X size={14} /> Clear
          </button>
        </div>
      )}

      {/* ── Error ───────────────────────────────────────────────────────────── */}
      {error && <div className="alert alert-error">{error}</div>}

      {/* ── Import Result ───────────────────────────────────────────────────── */}
      {importResult && (
        <div className="card" style={{ padding: 24 }}>
          <div className="card-title" style={{ marginBottom: 16, color: importResult.failed === 0 ? 'var(--accent)' : 'var(--warning)' }}>
            {importResult.failed === 0 ? <CheckCircle size={18} /> : <AlertCircle size={18} />}
            &nbsp; Import Complete — {importResult.file}
          </div>

          <div className="stats-grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', marginBottom: 16 }}>
            {[
              { label: 'Total Rows',  value: importResult.total_rows,  cls: 'blue' },
              { label: 'Imported',    value: importResult.imported,    cls: 'green' },
              { label: 'Skipped',     value: importResult.skipped,     cls: 'amber' },
              { label: 'Failed',      value: importResult.failed,      cls: importResult.failed > 0 ? 'red' : 'green' },
            ].map(s => (
              <div className="stat-card" key={s.label}>
                <div className={`stat-icon ${s.cls}`} />
                <div className="stat-label">{s.label}</div>
                <div className="stat-value">{s.value}</div>
              </div>
            ))}
          </div>

          {importResult.failed_rows?.length > 0 && (
            <div>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8, color: 'var(--danger)' }}>
                Failed rows (first {importResult.failed_rows.length}):
              </div>
              <div className="table-wrap">
                <table>
                  <thead><tr><th>Row</th><th>Error</th><th>Data</th></tr></thead>
                  <tbody>
                    {importResult.failed_rows.map((fr, i) => (
                      <tr key={i}>
                        <td>{fr.row}</td>
                        <td style={{ color: 'var(--danger)' }}>{fr.error}</td>
                        <td style={{ fontSize: 12, color: 'var(--muted)' }}>{JSON.stringify(fr.data)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}
    </AppLayout>
  );
}
