import React, { useEffect, useState } from 'react';
import AppLayout from '../components/AppLayout';
import LoadingSpinner from '../components/LoadingSpinner';
import { Settings as SettingsIcon, Save, RefreshCw, Trash2, AlertTriangle } from 'lucide-react';
import api from '../lib/api';

export default function Settings() {
  const [settings, setSettings] = useState({
    enable_ocr: 'true',
    enable_fuzzy: 'true',
    max_upload_size_mb: '100',
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);

  useEffect(() => {
    api.get('/settings').then((r) => {
      setSettings((prev) => ({ ...prev, ...r.data }));
    }).finally(() => setLoading(false));
  }, []);

  const handleSave = async () => {
    setSaving(true);
    try {
      await api.put('/settings', { settings });
      setMessage({ type: 'success', text: 'Settings saved successfully.' });
    } catch {
      setMessage({ type: 'error', text: 'Failed to save settings.' });
    } finally {
      setSaving(false);
      setTimeout(() => setMessage(null), 3000);
    }
  };

  const handleReprocessAll = async () => {
    if (!window.confirm('Reprocess ALL PDFs? This may take a long time.')) return;
    try {
      const res = await api.post('/settings/reprocess-all');
      setMessage({ type: 'success', text: `${res.data.queued} PDFs queued for reprocessing.` });
    } catch {
      setMessage({ type: 'error', text: 'Failed to queue reprocessing.' });
    }
  };

  const handleClearIndex = async () => {
    if (!window.confirm('Delete ALL indexed text? PDFs will remain but all search data will be cleared.')) return;
    try {
      await api.post('/settings/clear-index');
      setMessage({ type: 'success', text: 'Index cleared. PDFs are still stored.' });
    } catch {
      setMessage({ type: 'error', text: 'Failed to clear index.' });
    }
  };

  const Toggle = ({ settingKey, label, description }) => (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 0', borderBottom: '1px solid var(--border)' }}>
      <div>
        <div style={{ fontSize: 14, fontWeight: 500, color: 'var(--text)' }}>{label}</div>
        {description && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>{description}</div>}
      </div>
      <label className="toggle-switch">
        <input
          type="checkbox"
          checked={settings[settingKey] === 'true'}
          onChange={(e) => setSettings((s) => ({ ...s, [settingKey]: e.target.checked ? 'true' : 'false' }))}
        />
        <span className="toggle-slider"></span>
      </label>
    </div>
  );

  if (loading) return <AppLayout title="Settings"><LoadingSpinner /></AppLayout>;

  return (
    <AppLayout title="Settings">
      <div className="page-banner blue" style={{ marginBottom: 28 }}>
        <div className="banner-content">
          <div className="banner-title">Application Settings</div>
          <div className="banner-subtitle">Configure PDF extraction, search behavior, and data management.</div>
        </div>
        <SettingsIcon size={80} className="banner-icon" />
      </div>

      {message && (
        <div className={`alert alert-${message.type}`} style={{ marginBottom: 20 }}>
          {message.text}
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20, marginBottom: 20 }}>
        {/* PDF Extraction */}
        <div className="card" style={{ padding: '20px 24px' }}>
          <h3 style={{ fontSize: 15, fontWeight: 600, marginBottom: 4 }}>PDF Extraction</h3>
          <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 16 }}>Control how PDFs are processed.</p>
          <Toggle
            settingKey="enable_ocr"
            label="Enable OCR Fallback"
            description="Use Tesseract OCR for scanned/image PDFs"
          />
          <Toggle
            settingKey="enable_fuzzy"
            label="Enable Fuzzy Search"
            description="Find names with spelling variations"
          />
          <div style={{ padding: '16px 0' }}>
            <label className="form-label">Max Upload Size (MB)</label>
            <input
              className="form-input"
              type="number"
              min={1}
              max={500}
              value={settings.max_upload_size_mb}
              onChange={(e) => setSettings((s) => ({ ...s, max_upload_size_mb: e.target.value }))}
            />
          </div>
          <button className="btn btn-primary" onClick={handleSave} disabled={saving}>
            {saving ? <><div className="spinner" style={{ width: 14, height: 14 }}></div> Saving...</> : <><Save size={16} /> Save Settings</>}
          </button>
        </div>

        {/* Data Management */}
        <div className="card" style={{ padding: '20px 24px' }}>
          <h3 style={{ fontSize: 15, fontWeight: 600, marginBottom: 4 }}>Data Management</h3>
          <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 20 }}>Manage indexed data and reprocessing.</p>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            <div style={{ background: 'var(--bg)', borderRadius: 8, padding: 16, border: '1px solid var(--border)' }}>
              <div style={{ fontSize: 14, fontWeight: 500, marginBottom: 4 }}>Reprocess All PDFs</div>
              <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 12 }}>
                Re-extract text from all uploaded PDFs. Useful after changing extraction settings.
              </div>
              <button className="btn btn-secondary" onClick={handleReprocessAll}>
                <RefreshCw size={16} /> Reprocess All PDFs
              </button>
            </div>

            <div style={{ background: 'rgba(239,68,68,0.04)', borderRadius: 8, padding: 16, border: '1px solid rgba(239,68,68,0.15)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
                <AlertTriangle size={14} color="var(--danger)" />
                <span style={{ fontSize: 14, fontWeight: 500, color: 'var(--danger)' }}>Danger Zone</span>
              </div>
              <div style={{ fontSize: 12, color: 'var(--muted)', marginBottom: 12 }}>
                Delete all indexed text. PDFs will remain but must be reprocessed before searching.
              </div>
              <button className="btn btn-danger" onClick={handleClearIndex}>
                <Trash2 size={16} /> Clear All Indexed Text
              </button>
            </div>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
