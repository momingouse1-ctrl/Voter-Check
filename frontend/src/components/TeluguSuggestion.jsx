import React, { useState, useEffect, useRef } from 'react';
import { Sparkles, Check, Edit3, RefreshCw, ChevronRight } from 'lucide-react';
import api from '../lib/api';

/**
 * TeluguSuggestion component
 * 
 * When user types an English name, this component:
 * 1. Calls /api/search/transliterate to get Telugu suggestions
 * 2. Shows suggestions for user to pick or edit
 * 3. Emits confirmed Telugu text via onConfirm callback
 * 
 * Props:
 *   englishText   — the English input text to transliterate
 *   onConfirm     — callback(teluguText) when user confirms
 *   onClear       — callback when user clears the Telugu name
 *   label         — label string (default "Telugu Name Suggestion")
 *   disabled      — bool
 */
export default function TeluguSuggestion({ englishText = '', onConfirm, onClear, label = 'Telugu Name Suggestion', disabled = false }) {
  const [suggestions, setSuggestions] = useState([]);
  const [loading, setLoading]         = useState(false);
  const [confirmed, setConfirmed]     = useState('');
  const [editing, setEditing]         = useState(false);
  const [editValue, setEditValue]     = useState('');
  const [error, setError]             = useState('');
  const prevText = useRef('');
  const debounceTimer = useRef(null);

  // Auto-fetch when englishText changes (debounced)
  useEffect(() => {
    if (!englishText || englishText.trim().length < 2) {
      setSuggestions([]);
      setConfirmed('');
      setError('');
      return;
    }

    if (englishText === prevText.current) return;
    prevText.current = englishText;

    clearTimeout(debounceTimer.current);
    debounceTimer.current = setTimeout(() => {
      fetchSuggestions(englishText);
    }, 600);

    return () => clearTimeout(debounceTimer.current);
  }, [englishText]);

  // Clear confirmed when english text cleared
  useEffect(() => {
    if (!englishText && confirmed) {
      setConfirmed('');
      onClear?.();
    }
  }, [englishText]);

  const fetchSuggestions = async (text, count = 3) => {
    setLoading(true);
    setError('');
    setSuggestions([]);
    try {
      const res = await api.post('/search/transliterate', { text: text.trim(), count });
      if (res.data.is_telugu) {
        // Already Telugu — auto-confirm
        handleUse(text);
      } else {
        setSuggestions(res.data.suggestions || []);
      }
    } catch (e) {
      setError('Transliteration unavailable. You can type Telugu manually.');
    } finally {
      setLoading(false);
    }
  };

  const handleUse = (text) => {
    setConfirmed(text);
    setEditing(false);
    setEditValue(text);
    onConfirm?.(text);
  };

  const handleStartEdit = () => {
    setEditing(true);
    setEditValue(confirmed || (suggestions[0] ?? ''));
  };

  const handleSaveEdit = () => {
    if (editValue.trim()) {
      handleUse(editValue.trim());
    }
    setEditing(false);
  };

  const handleMore = () => {
    if (englishText) fetchSuggestions(englishText, 6);
  };

  const handleClear = () => {
    setConfirmed('');
    setSuggestions([]);
    setEditing(false);
    setEditValue('');
    onClear?.();
  };

  if (!englishText || englishText.trim().length < 2) return null;

  return (
    <div className="transliteration-panel">
      <div className="transliteration-label">
        <Sparkles size={14} />
        <span>{label}</span>
        {loading && <div className="spinner" style={{ width: 12, height: 12, marginLeft: 4 }} />}
      </div>

      {error && (
        <div className="transliteration-error">{error}</div>
      )}

      {/* Confirmed state */}
      {confirmed && !editing && (
        <div className="transliteration-confirmed">
          <div className="transliteration-confirmed-text">
            <Check size={14} />
            <span className="telugu-text">{confirmed}</span>
          </div>
          <div style={{ display: 'flex', gap: 6 }}>
            <button className="btn btn-secondary btn-xs" onClick={handleStartEdit}>
              <Edit3 size={12} /> Edit
            </button>
            <button className="btn btn-secondary btn-xs" onClick={handleClear}>
              ✕ Clear
            </button>
          </div>
        </div>
      )}

      {/* Edit mode */}
      {editing && (
        <div className="transliteration-edit">
          <input
            className="transliteration-edit-input"
            value={editValue}
            onChange={e => setEditValue(e.target.value)}
            placeholder="Type Telugu name..."
            autoFocus
            onKeyDown={e => { if (e.key === 'Enter') handleSaveEdit(); }}
          />
          <button className="btn btn-primary btn-sm" onClick={handleSaveEdit}>
            <Check size={14} /> Use This
          </button>
          <button className="btn btn-secondary btn-sm" onClick={() => setEditing(false)}>
            Cancel
          </button>
        </div>
      )}

      {/* Suggestions list */}
      {!confirmed && !editing && suggestions.length > 0 && (
        <div className="transliteration-suggestions">
          {suggestions.map((s, i) => (
            <div key={i} className="transliteration-suggestion-item">
              <span className="telugu-text suggestion-text">{s}</span>
              <button
                className="btn btn-primary btn-xs"
                onClick={() => handleUse(s)}
                disabled={disabled}
              >
                <ChevronRight size={12} /> Use
              </button>
            </div>
          ))}
          <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
            <button className="btn btn-secondary btn-xs" onClick={handleStartEdit}>
              <Edit3 size={12} /> Type Manually
            </button>
            <button className="btn btn-secondary btn-xs" onClick={handleMore}>
              <RefreshCw size={12} /> More Suggestions
            </button>
          </div>
        </div>
      )}

      {!confirmed && !editing && !loading && suggestions.length === 0 && englishText.trim().length >= 2 && (
        <div style={{ display: 'flex', gap: 8, marginTop: 6 }}>
          <button className="btn btn-secondary btn-xs" onClick={handleStartEdit}>
            <Edit3 size={12} /> Type Telugu Manually
          </button>
        </div>
      )}
    </div>
  );
}
