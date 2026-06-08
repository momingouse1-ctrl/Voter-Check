import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FileText, CheckCircle, Search, Zap, Shield } from 'lucide-react';
import { setAuth } from '../lib/auth';
import api from '../lib/api';

export default function EmailGate() {
  const [email, setEmailValue] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!email.trim()) { setError('Please enter your email address.'); return; }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) { setError('Please enter a valid email address.'); return; }

    setLoading(true);
    setError('');

    try {
      const res = await api.post('/session/email', { email: email.trim() });
      setAuth(res.data.email, res.data.is_admin);
      navigate(res.data.is_admin ? '/dashboard' : '/search');
    } catch (err) {
      setError(err.response?.data?.message || 'Something went wrong. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="email-gate">
      <div className="email-gate-card">
        <div className="gate-badge">
          <FileText size={12} />
          PDF Name Finder
        </div>

        <h1 className="gate-title">
          Search <span>Kadapa</span><br />Voter Records
        </h1>
        <p className="gate-subtitle">
          Kadapa city voter records are already indexed. Enter a name to find part number, serial number, PDF page, and voter details in English or Telugu.
        </p>

        <form className="gate-form" onSubmit={handleSubmit}>
          <input
            id="email-input"
            type="email"
            className="gate-input"
            placeholder="Enter your email to continue..."
            value={email}
            onChange={(e) => setEmailValue(e.target.value)}
            autoFocus
          />
          {error && <div className="gate-error">{error}</div>}
          <button
            id="continue-btn"
            type="submit"
            className="gate-btn"
            disabled={loading}
          >
            {loading ? 'Please wait...' : <>Continue &rarr;</>}
          </button>
        </form>

        <div className="gate-features">
          {[
            { icon: CheckCircle, text: 'Kadapa Records Ready' },
            { icon: Search, text: 'Smart Name Search' },
            { icon: Zap, text: 'Part & Serial Details' },
            { icon: Shield, text: 'Secure & Private' },
          ].map(({ icon: Icon, text }) => (
            <div className="gate-feature" key={text}>
              <Icon size={13} />
              <span>{text}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
