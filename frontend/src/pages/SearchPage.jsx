import React, { useState, useEffect } from 'react';
import AppLayout from '../components/AppLayout';
import ConfidenceBadge from '../components/ConfidenceBadge';
import LoadingSpinner from '../components/LoadingSpinner';
import EmptyState from '../components/EmptyState';
import TeluguSuggestion from '../components/TeluguSuggestion';
import GeographyFilters from '../components/GeographyFilters';
import {
  Search, Eye, Copy, Clock, Globe, MapPin, Hash, CreditCard,
  ChevronDown, ChevronUp, RotateCcw, User, Home, AlertCircle,
} from 'lucide-react';
import api from '../lib/api';
import { getEmail } from '../lib/auth';

const SCOPES = [
  { key: 'all',             label: 'All Districts' },
  { key: 'district',        label: 'By District' },
  { key: 'city',            label: 'By City/Town' },
  { key: 'assembly',        label: 'By Assembly' },
  { key: 'polling_station', label: 'By Polling Station' },
];

const MODES = [
  { key: 'fuzzy',   label: 'Fuzzy' },
  { key: 'partial', label: 'Partial' },
  { key: 'exact',   label: 'Exact' },
];

export default function SearchPage() {
  // Scope + geography
  const [scope,      setScope]      = useState('all');
  const [geoFilters, setGeoFilters] = useState({});

  // Name fields
  const [nameEn,        setNameEn]        = useState('');
  const [nameTe,        setNameTe]        = useState(''); // confirmed Telugu from suggestion
  const [relativeEn,    setRelativeEn]    = useState('');
  const [relativeTe,    setRelativeTe]    = useState('');

  // Extra fields (advanced)
  const [houseNumber, setHouseNumber] = useState('');
  const [epicNumber,  setEpicNumber]  = useState('');
  const [mode,        setMode]        = useState('fuzzy');
  const [showAdvanced, setShowAdvanced] = useState(false);

  // Search state
  const [results,  setResults]  = useState(null);
  const [loading,  setLoading]  = useState(false);
  const [error,    setError]    = useState('');
  const [recent,   setRecent]   = useState([]);
  const [copied,   setCopied]   = useState(null);

  const email = getEmail();

  useEffect(() => {
    api.get('/search/recent', { params: { email } })
      .then(r => setRecent(r.data))
      .catch(() => {});
  }, []);

  const handleReset = () => {
    setNameEn(''); setNameTe(''); setRelativeEn(''); setRelativeTe('');
    setHouseNumber(''); setEpicNumber('');
    setScope('all'); setGeoFilters({});
    setMode('fuzzy'); setResults(null); setError('');
  };

  const handleSearch = async (e) => {
    if (e) e.preventDefault();

    const hasEpic   = epicNumber.trim();
    const hasHouse  = houseNumber.trim();
    const hasName   = (nameEn.trim() || nameTe.trim());

    if (!hasEpic && !hasHouse && !hasName) {
      setError('Please enter a name, EPIC number, or house number to search.');
      return;
    }

    setLoading(true); setError(''); setResults(null);

    try {
      const payload = {
        scope,
        epic_number:        hasEpic  || undefined,
        house_number:       hasHouse || undefined,
        query:              nameEn.trim() || undefined,
        name_te:            nameTe.trim() || undefined,
        relative_query:     relativeEn.trim() || undefined,
        relative_name_te:   relativeTe.trim() || undefined,
        mode,
        email,
        ...(scope !== 'all' ? geoFilters : {}),
      };

      const res = await api.post('/search', payload);
      setResults(res.data);
      api.get('/search/recent', { params: { email } }).then(r => setRecent(r.data));
    } catch (err) {
      setError(err.response?.data?.message || err.response?.data?.error || 'Search failed. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const copyResult = (r) => {
    const lines = [
      r.voter_name && `Name: ${r.voter_name}`,
      r.relative_name && `Relative: ${r.relative_name}`,
      r.house_no && `House No: ${r.house_no}`,
      r.voter_id && `EPIC: ${r.voter_id}`,
      r.age && `Age: ${r.age}`,
      r.gender && `Gender: ${r.gender}`,
      r.part_no && `Part No: ${r.part_no}`,
      r.district_name && `District: ${r.district_name}`,
      r.city_name && `City: ${r.city_name}`,
      r.pdf_name && `Source: ${r.pdf_name}`,
      r.page_number && `Page: ${r.page_number}`,
    ].filter(Boolean).join('\n');
    navigator.clipboard.writeText(lines || r.matched_text || '');
    setCopied(r.result_key || r.page_id);
    setTimeout(() => setCopied(null), 2000);
  };

  const openPdf = (pdfId) => window.open(`/api/pdfs/${pdfId}/file`, '_blank');

  const isEpicSearch  = results?.search_type === 'epic';
  const isHouseSearch = results?.search_type === 'house_number';

  return (
    <AppLayout title="Search Voter Records">
      {/* Page Banner */}
      <div className="page-banner purple">
        <div className="banner-content">
          <div className="banner-title">Telugu Voter Record Search</div>
          <div className="banner-subtitle">
            Search by name, house number, or EPIC ID across Kadapa voter records.
            Type English names — we'll suggest Telugu script for you.
          </div>
        </div>
        <Search size={80} className="banner-icon" />
      </div>

      {/* Main Search Form */}
      <form onSubmit={handleSearch}>
        {/* ── Scope Selector ─────────────────────────────────────────────────── */}
        <div className="scope-selector">
          <span className="scope-label"><Globe size={14} /> Search Scope:</span>
          <div className="scope-pills">
            {SCOPES.map(s => (
              <button
                key={s.key}
                type="button"
                id={`scope-${s.key}`}
                className={`scope-pill ${scope === s.key ? 'active' : ''}`}
                onClick={() => { setScope(s.key); setGeoFilters({}); }}
              >
                {s.label}
              </button>
            ))}
          </div>
        </div>

        {/* ── Geography Filters (cascading dropdowns) ──────────────────────── */}
        {scope !== 'all' && (
          <GeographyFilters value={geoFilters} onChange={setGeoFilters} scope={scope} />
        )}

        {/* ── Name Inputs ──────────────────────────────────────────────────── */}
        <div className="search-form-grid">
          {/* Name (English) */}
          <div className="search-form-field">
            <label className="search-form-label"><User size={13} /> Name (English or Telugu)</label>
            <input
              id="name-en-input"
              type="text"
              className="search-input"
              placeholder="e.g. Abdul Rafi Shaik  or  అబ్దుల్ రఫీ షేక్"
              value={nameEn}
              onChange={e => { setNameEn(e.target.value); if (!e.target.value) setNameTe(''); }}
              autoFocus
            />
            <TeluguSuggestion
              englishText={nameEn}
              onConfirm={setNameTe}
              onClear={() => setNameTe('')}
              label="Telugu Name Suggestion"
            />
          </div>

          {/* Relative Name (English) */}
          <div className="search-form-field">
            <label className="search-form-label"><User size={13} /> Father / Husband Name (optional)</label>
            <input
              id="relative-en-input"
              type="text"
              className="search-input"
              placeholder="e.g. Nawaz Ali Khan  or  నవాజ్ అలీ ఖాన్"
              value={relativeEn}
              onChange={e => { setRelativeEn(e.target.value); if (!e.target.value) setRelativeTe(''); }}
            />
            <TeluguSuggestion
              englishText={relativeEn}
              onConfirm={setRelativeTe}
              onClear={() => setRelativeTe('')}
              label="Telugu Relative Name Suggestion"
            />
          </div>
        </div>

        {/* ── Quick fields: House No + EPIC ───────────────────────────────── */}
        <div className="search-form-grid">
          <div className="search-form-field">
            <label className="search-form-label"><Home size={13} /> House Number</label>
            <input
              id="house-number-input"
              type="text"
              className="search-input"
              placeholder="e.g. 7/106  or  19-122  or  H.No 19/122"
              value={houseNumber}
              onChange={e => setHouseNumber(e.target.value)}
            />
          </div>
          <div className="search-form-field">
            <label className="search-form-label"><CreditCard size={13} /> EPIC / Voter ID</label>
            <input
              id="epic-input"
              type="text"
              className="search-input"
              placeholder="e.g. AP23154204223"
              value={epicNumber}
              onChange={e => setEpicNumber(e.target.value.toUpperCase())}
            />
          </div>
        </div>

        {/* ── Advanced Toggle ──────────────────────────────────────────────── */}
        <div className="search-form-advanced-row">
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => setShowAdvanced(v => !v)}
          >
            {showAdvanced ? <ChevronUp size={14} /> : <ChevronDown size={14} />}
            {showAdvanced ? 'Hide Advanced' : 'Advanced Options'}
          </button>

          {/* Mode selector (always visible but compact) */}
          <div className="mode-pills">
            <span style={{ fontSize: 12, color: 'var(--muted)' }}>Mode:</span>
            {MODES.map(m => (
              <button
                key={m.key}
                type="button"
                id={`mode-${m.key}`}
                className={`mode-pill ${mode === m.key ? 'active' : ''}`}
                onClick={() => setMode(m.key)}
              >
                {m.label}
              </button>
            ))}
          </div>
        </div>

        {/* ── Advanced Fields ──────────────────────────────────────────────── */}
        {showAdvanced && (
          <div className="search-form-grid" style={{ marginTop: 0 }}>
            <div className="search-form-field">
              <label className="search-form-label"><Hash size={13} /> Part Number</label>
              <input className="search-input" placeholder="e.g. 45" id="part-number-input" />
            </div>
            <div className="search-form-field">
              <label className="search-form-label">Age</label>
              <input className="search-input" placeholder="e.g. 35" id="age-input" />
            </div>
          </div>
        )}

        {/* ── Action Buttons ───────────────────────────────────────────────── */}
        <div className="search-action-row">
          <button
            id="search-submit-btn"
            type="submit"
            className="btn btn-primary search-btn-main"
            disabled={loading}
          >
            {loading
              ? <><div className="spinner" style={{ width: 16, height: 16 }} /> Searching...</>
              : <><Search size={18} /> Search Records</>}
          </button>
          <button type="button" className="btn btn-secondary" onClick={handleReset} id="reset-btn">
            <RotateCcw size={16} /> Reset
          </button>
        </div>

        {error && <div className="alert alert-error" style={{ marginTop: 12 }}>{error}</div>}
      </form>

      {/* ── Loading ──────────────────────────────────────────────────────────── */}
      {loading && <LoadingSpinner large text="Searching voter records..." />}

      {/* ── Results ──────────────────────────────────────────────────────────── */}
      {results && !loading && (
        <div style={{ marginTop: 24 }}>
          {/* Result header */}
          <div className="results-header">
            <div>
              <span className="results-count">{results.total}</span>
              <span className="results-label">
                {results.total === 0 ? ' records found' : ` record${results.total !== 1 ? 's' : ''} found`}
                {results.query ? ` for "${results.query}"` : ''}
                {isEpicSearch ? ' · EPIC search' : ''}
                {isHouseSearch ? ' · House number search' : ''}
              </span>
            </div>
          </div>

          {results.total === 0 ? (
            <EmptyState
              type="noSearch"
              title="No matching records found"
              subtitle='Try fuzzy mode, partial spelling, or remove some filters. For names like "Shaik", try just the surname.'
            />
          ) : (
            <div className="results-list">
              {results.results.map((r, i) => {
                const isExcel = r.source_type === 'excel';
                const isCopied = copied === (r.result_key || r.page_id);

                return (
                  <div className="result-card-v2" key={r.result_key || r.page_id || i}>
                    {/* Card Header */}
                    <div className="result-card-header">
                      <div className="result-card-name">
                        <span className="telugu-text result-voter-name">
                          {r.voter_name || r.matched_text || '—'}
                        </span>
                        {r.voter_id && (
                          <span className="result-epic-badge">EPIC: {r.voter_id}</span>
                        )}
                      </div>
                      <ConfidenceBadge confidence={r.confidence} />
                    </div>

                    {/* Card Body */}
                    <div className="result-card-body">
                      {r.relative_name && (
                        <div className="result-field">
                          <span className="result-field-label">Relative</span>
                          <span className="result-field-value telugu-text">{r.relative_name}</span>
                        </div>
                      )}
                      {r.house_no && (
                        <div className="result-field">
                          <span className="result-field-label"><Home size={11} /> House No</span>
                          <span className="result-field-value">{r.house_no}</span>
                        </div>
                      )}
                      {(r.age || r.gender) && (
                        <div className="result-field">
                          <span className="result-field-label">Age / Gender</span>
                          <span className="result-field-value">
                            {[r.age, r.gender].filter(Boolean).join(' · ')}
                          </span>
                        </div>
                      )}
                      <div className="result-field">
                        <span className="result-field-label"><MapPin size={11} /> Location</span>
                        <span className="result-field-value">
                          {[r.district_name, r.city_name, r.assembly_name].filter(Boolean).join(' › ') || 'Kadapa'}
                        </span>
                      </div>
                      {(r.part_no || r.serial_no || r.roll_page_no) && (
                        <div className="result-field">
                          <span className="result-field-label">Part / Serial</span>
                          <span className="result-field-value">
                            {r.part_no && `Part ${r.part_no}`}
                            {r.serial_no && ` · Serial ${r.serial_no}`}
                            {r.roll_page_no && ` · Roll Page ${r.roll_page_no}`}
                          </span>
                        </div>
                      )}
                      {(isExcel ? r.page_number : r.page_number) && (
                        <div className="result-field">
                          <span className="result-field-label">Source</span>
                          <span className="result-field-value">
                            {r.pdf_name} {r.page_number ? `· Page ${r.page_number}` : ''}
                          </span>
                        </div>
                      )}
                    </div>

                    {/* Context block */}
                    {r.context && (
                      <div className="result-context telugu-text">{r.context}</div>
                    )}

                    {/* Actions */}
                    <div className="result-actions">
                      {!isExcel && r.pdf_id && (
                        <button
                          className="btn btn-primary btn-sm"
                          onClick={() => openPdf(r.pdf_id)}
                          id={`view-pdf-${r.pdf_id}-${r.page_number}`}
                        >
                          <Eye size={14} /> View PDF
                        </button>
                      )}
                      <button
                        className="btn btn-secondary btn-sm"
                        onClick={() => copyResult(r)}
                        id={`copy-${r.result_key || r.page_id}`}
                      >
                        <Copy size={14} /> {isCopied ? 'Copied!' : 'Copy'}
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* ── Empty State / Recent Searches ────────────────────────────────────── */}
      {!results && !loading && (
        <div>
          <EmptyState
            type="noSearch"
            title="Search across Telugu voter records"
            subtitle="Enter a name in English — the system will suggest the Telugu version for accurate matching. You can also search by EPIC number or house number."
          />

          {recent.length > 0 && (
            <div className="card" style={{ marginTop: 24 }}>
              <div className="card-header">
                <span className="card-title">Recent Searches</span>
                <Clock size={16} color="var(--muted)" />
              </div>
              <div style={{ padding: '8px 16px' }}>
                {recent.map(s => (
                  <button
                    key={s.id}
                    onClick={() => { setNameEn(s.query); }}
                    style={{
                      display: 'block', width: '100%', textAlign: 'left',
                      padding: '8px 0', borderBottom: '1px solid var(--border)',
                      background: 'none', border: 'none', borderRadius: 0,
                      cursor: 'pointer', fontSize: 14, color: 'var(--text)',
                    }}
                  >
                    <span style={{ color: 'var(--primary)' }}>{s.query}</span>
                    <span style={{ fontSize: 12, color: 'var(--muted)', marginLeft: 8 }}>
                      {s.total_results} results
                    </span>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </AppLayout>
  );
}
