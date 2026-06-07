import React, { useEffect, useState } from 'react';
import AppLayout from '../components/AppLayout';
import LoadingSpinner from '../components/LoadingSpinner';
import StatusBadge from '../components/StatusBadge';
import { FileText, Layers, Search, CheckCircle, XCircle, Clock, LayoutDashboard, PlayCircle } from 'lucide-react';
import api from '../lib/api';
import { useNavigate } from 'react-router-dom';

export default function Dashboard() {
  const [stats, setStats] = useState(null);
  const [recent, setRecent] = useState([]);
  const [loading, setLoading] = useState(true);
  const [queueStarting, setQueueStarting] = useState(false);
  const navigate = useNavigate();

  const loadDashboard = () => {
    return Promise.all([
      api.get('/stats'),
      api.get('/search/recent'),
    ]).then(([statsRes, recentRes]) => {
      setStats(statsRes.data);
      setRecent(recentRes.data);
    });
  };

  useEffect(() => {
    loadDashboard().finally(() => setLoading(false));
  }, []);

  const startQueue = async () => {
    setQueueStarting(true);
    try {
      await api.post('/settings/process-queue');
      await loadDashboard();
    } finally {
      setQueueStarting(false);
    }
  };

  if (loading) return (
    <AppLayout title="Dashboard">
      <LoadingSpinner large text="Loading dashboard..." />
    </AppLayout>
  );

  const cards = [
    { label: 'Total PDFs',     value: stats?.total_pdfs ?? 0,      icon: FileText,     cls: 'blue'   },
    { label: 'Total Pages',    value: stats?.total_pages ?? 0,     icon: Layers,       cls: 'indigo' },
    { label: 'Indexed PDFs',   value: stats?.indexed_pdfs ?? 0,    icon: CheckCircle,  cls: 'green'  },
    { label: 'Queued PDFs',     value: stats?.queued_pdfs ?? 0,      icon: Clock,        cls: 'amber'  },
    { label: 'Processing',     value: stats?.processing_pdfs ?? 0, icon: PlayCircle,   cls: 'purple' },
    { label: 'Failed PDFs',    value: stats?.failed_pdfs ?? 0,     icon: XCircle,      cls: 'red'    },
    { label: 'Total Searches', value: stats?.total_searches ?? 0,  icon: Search,       cls: 'indigo' },
  ];

  return (
    <AppLayout title="Dashboard">
      {/* Banner */}
      <div className="page-banner blue">
        <div className="banner-content">
          <div className="banner-title">Your PDF Search Workspace</div>
          <div className="banner-subtitle">
            Track uploads, processing status, and search results from one clean dashboard.
          </div>
        </div>
        <LayoutDashboard size={80} className="banner-icon" />
      </div>

      {/* Stats */}
      <div className="stats-grid">
        {cards.map(({ label, value, icon: Icon, cls }) => (
          <div className="stat-card" key={label}>
            <div className={`stat-icon ${cls}`}><Icon size={18} /></div>
            <div className="stat-label">{label}</div>
            <div className="stat-value">{value.toLocaleString()}</div>
          </div>
        ))}
      </div>

      {/* Quick Actions */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 16, marginBottom: 24 }}>
        <div className="card" style={{ padding: 24 }}>
          <h3 className="card-title" style={{ marginBottom: 8 }}>Quick Upload</h3>
          <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 16 }}>
            Upload new PDF files to the index.
          </p>
          <button className="btn btn-primary" onClick={() => navigate('/upload')}>
            <FileText size={16} /> Upload PDFs
          </button>
        </div>
        <div className="card" style={{ padding: 24 }}>
          <h3 className="card-title" style={{ marginBottom: 8 }}>Process Queue</h3>
          <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 16 }}>
            Start background indexing for PDFs waiting in the queue.
          </p>
          <button className="btn btn-secondary" onClick={startQueue} disabled={queueStarting || (stats?.queued_pdfs ?? 0) === 0}>
            {queueStarting ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <PlayCircle size={16} />}
            {queueStarting ? 'Starting...' : 'Process Queue Now'}
          </button>
        </div>
        <div className="card" style={{ padding: 24 }}>
          <h3 className="card-title" style={{ marginBottom: 8 }}>Search Names</h3>
          <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 16 }}>
            Find any name across all indexed PDFs.
          </p>
          <button className="btn btn-accent" onClick={() => navigate('/search')}>
            <Search size={16} /> Search Now
          </button>
        </div>
      </div>

      {/* Recent Searches */}
      <div className="card">
        <div className="card-header">
          <span className="card-title">Recent Searches</span>
          <span style={{ fontSize: 12, color: 'var(--muted)' }}>{recent.length} entries</span>
        </div>
        <div className="table-wrap">
          {recent.length === 0 ? (
            <div className="empty-state" style={{ padding: '32px' }}>
              <div className="empty-state-sub">No searches yet. Start searching!</div>
            </div>
          ) : (
            <table>
              <thead>
                <tr>
                  <th>Query</th>
                  <th>Mode</th>
                  <th>Results</th>
                  <th>When</th>
                </tr>
              </thead>
              <tbody>
                {recent.map((s) => (
                  <tr key={s.id}>
                    <td style={{ fontWeight: 500 }}>{s.query}</td>
                    <td><span className="badge badge-queued">{s.mode}</span></td>
                    <td>{s.total_results}</td>
                    <td style={{ color: 'var(--muted)' }}>
                      {new Date(s.created_at).toLocaleDateString()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
