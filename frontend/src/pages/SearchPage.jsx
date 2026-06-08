import React, { useState, useEffect } from 'react';
import AppLayout from '../components/AppLayout';
import ConfidenceBadge from '../components/ConfidenceBadge';
import LoadingSpinner from '../components/LoadingSpinner';
import EmptyState from '../components/EmptyState';
import { Search, Download, Eye, Copy, Clock } from 'lucide-react';
import api from '../lib/api';
import { getEmail } from '../lib/auth';

const MODES = [
  { key: 'fuzzy', label: 'Fuzzy Search' },
  { key: 'partial', label: 'Partial Match' },
  { key: 'exact', label: 'Exact Match' },
];

export default function SearchPage() {
  const [query, setQuery] = useState('');
  const [relativeQuery, setRelativeQuery] = useState('');
  const [mode, setMode] = useState('fuzzy');
  const [results, setResults] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [recent, setRecent] = useState([]);
  const [copied, setCopied] = useState(null);
  const email = getEmail();

  useEffect(() => {
    api.get('/search/recent', { params: { email } })
      .then((r) => setRecent(r.data))
      .catch(() => {});
  }, []);

  const handleSearch = async (e, overrideQuery = null) => {
    if (e) e.preventDefault();
    const q = overrideQuery ?? query;
    if (!q.trim()) { setError('Please enter a name to search.'); return; }

    setLoading(true);
    setError('');
    setResults(null);

    try {
      const res = await api.post('/search', {
        query: q.trim(),
        relative_query: relativeQuery.trim() || undefined,
        mode,
        email,
      });
      setResults(res.data);
      api.get('/search/recent', { params: { email } }).then((r) => setRecent(r.data));
    } catch (err) {
      setError(err.response?.data?.message || 'Search failed. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  const copyResult = (r) => {
    const isExcel = r.source_type === 'excel';
    const text = isExcel
      ? `Excel: ${r.pdf_name}\nPart No: ${r.part_no ?? '-'}\nSerial No: ${r.serial_no ?? '-'}\nPDF Page: ${r.page_number ?? '-'}\nMatched: ${r.matched_text}\nContext:\n${r.context}`
      : `PDF: ${r.pdf_name}\nPage: ${r.page_number}\nMatched: ${r.matched_text}\nContext:\n${r.context}`;

    navigator.clipboard.writeText(text);
    setCopied(r.page_id);
    setTimeout(() => setCopied(null), 2000);
  };

  const openPdf = (pdfId) => {
    window.open(`/api/pdfs/${pdfId}/file`, '_blank');
  };

  const downloadPdf = (pdfId) => {
    window.open(`/api/pdfs/${pdfId}/download`, '_blank');
  };

  return (
    <AppLayout title="Search Names">
      <div className="page-banner purple">
        <div className="banner-content">
          <div className="banner-title">Find Names Instantly</div>
          <div className="banner-subtitle">
            Search English or Telugu names across indexed PDFs and Excel voter records. Fuzzy, partial, and exact match supported.
          </div>
        </div>
        <Search size={80} className="banner-icon" />
      </div>

      <div className="search-box-wrap">
        <form onSubmit={handleSearch}>
          <div className="search-input-row">
            <input
              id="main-search-input"
              type="text"
              className="search-input"
              placeholder="Enter voter name, surname, village... (English or Telugu)"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              autoFocus
            />
            <input
              id="relative-search-input"
              type="text"
              className="search-input search-input-relative"
              placeholder="Father / Husband / Wife name (optional)"
              value={relativeQuery}
              onChange={(e) => setRelativeQuery(e.target.value)}
            />
            <button
              id="search-submit-btn"
              type="submit"
              className="search-btn"
              disabled={loading}
            >
              {loading
                ? <><div className="spinner" style={{ width: 16, height: 16 }}></div> Searching...</>
                : <><Search size={18} /> Search</>}
            </button>
          </div>

          <div className="search-filters">
            <span className="filter-label">Mode:</span>
            {MODES.map((m) => (
              <button
                key={m.key}
                type="button"
                id={`mode-${m.key}`}
                className={`filter-btn ${mode === m.key ? 'active' : ''}`}
                onClick={() => setMode(m.key)}
              >
                {m.label}
              </button>
            ))}
          </div>
        </form>

        {error && <div className="alert alert-error" style={{ marginTop: 12 }}>{error}</div>}
      </div>

      {loading && <LoadingSpinner large text="Searching across indexed PDFs and Excel voter records..." />}

      {results && !loading && (
        <div>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
            <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--text)' }}>
              {results.total === 0
                ? 'No results found'
                : `Found ${results.total} match${results.total !== 1 ? 'es' : ''} for "${results.query}"${results.relative_query ? ` with relative "${results.relative_query}"` : ''}`}
            </div>
            {results.total > 0 && (
              <span style={{ fontSize: 13, color: 'var(--muted)' }}>{results.total} result{results.total !== 1 ? 's' : ''}</span>
            )}
          </div>

          {results.total === 0 ? (
            <EmptyState
              type="noSearch"
              title="No matching name found"
              subtitle='Try partial spelling or fuzzy search mode. For example, try just the surname like "Gandluru" or "Khajapeer".'
            />
          ) : (
            results.results.map((r, i) => {
              const isExcel = r.source_type === 'excel';

              return (
                <div className="result-card" key={r.result_key || r.page_id || `${r.pdf_id}-${r.page_number}-${i}`}>
                  <div className="result-header">
                    <div style={{ flex: 1 }}>
                      <div className="result-meta">
                        <span className="result-pdf-name">{isExcel ? 'Excel Voter Record' : r.pdf_name}</span>
                        {isExcel ? (
                          <>
                            <span className="result-page">Part {r.part_no ?? '-'}</span>
                            <span className="result-page">Serial {r.serial_no ?? '-'}</span>
                          </>
                        ) : (
                          <span className="result-page">Page {r.page_number}</span>
                        )}
                        <ConfidenceBadge confidence={r.confidence} />
                      </div>
                      <div className="result-matched">{r.matched_text || '(see context below)'}</div>
                    </div>
                  </div>

                  {r.context && (
                    <div className="result-context">{r.context}</div>
                  )}

                  <div className="result-actions">
                    {!isExcel && (
                      <>
                        <button
                          className="btn btn-primary btn-sm"
                          onClick={() => openPdf(r.pdf_id)}
                          title="View PDF in browser"
                          id={`view-pdf-${r.pdf_id}-${r.page_number}`}
                        >
                          <Eye size={14} /> View PDF
                        </button>
                        <button
                          className="btn btn-secondary btn-sm"
                          onClick={() => downloadPdf(r.pdf_id)}
                          id={`download-pdf-${r.pdf_id}-${r.page_number}`}
                        >
                          <Download size={14} /> Download
                        </button>
                      </>
                    )}
                    <button
                      className="btn btn-secondary btn-sm"
                      onClick={() => copyResult(r)}
                      id={`copy-result-${r.pdf_id ?? r.record_id}-${r.page_number ?? r.source_row}`}
                    >
                      <Copy size={14} /> {copied === r.page_id ? 'Copied!' : 'Copy'}
                    </button>
                  </div>

                  <div style={{ marginTop: 8, fontSize: 12, color: 'var(--muted)' }}>
                    {isExcel ? (
                      <>Excel row <strong>{r.source_row}</strong> from <strong>{r.pdf_name}</strong></>
                    ) : (
                      <>Open page <strong>{r.page_number}</strong> in <strong>{r.pdf_name}</strong></>
                    )}
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}

      {!results && !loading && (
        <div>
          <EmptyState
            type="noSearch"
            title="Search across PDFs and Excel voter records"
            subtitle="Type a name above. Supports Telugu and English names, relative names, partial spellings, and fuzzy matching."
          />
          {recent.length > 0 && (
            <div className="card" style={{ marginTop: 24 }}>
              <div className="card-header">
                <span className="card-title">Recent Searches</span>
                <Clock size={16} color="var(--muted)" />
              </div>
              <div style={{ padding: '8px 16px' }}>
                {recent.map((s) => (
                  <button
                    key={s.id}
                    onClick={() => { setQuery(s.query); handleSearch(null, s.query); }}
                    style={{
                      display: 'block',
                      width: '100%',
                      textAlign: 'left',
                      padding: '8px 0',
                      borderBottom: '1px solid var(--border)',
                      background: 'none',
                      border: 'none',
                      cursor: 'pointer',
                      fontSize: 14,
                      color: 'var(--text)',
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
