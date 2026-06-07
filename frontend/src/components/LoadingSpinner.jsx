import React from 'react';

export default function LoadingSpinner({ large = false, text = '' }) {
  return (
    <div className="loading-center">
      <div className={`spinner ${large ? 'spinner-lg' : ''}`}></div>
      {text && <span>{text}</span>}
    </div>
  );
}
