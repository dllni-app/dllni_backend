(function () {
  const config = window.firebaseTokenDebugConfig ?? {};
  const query = new URLSearchParams(window.location.search);

  const elements = {
    acquireButton: document.querySelector('[data-testid="acquire-token"]'),
    apiEndpoint: document.querySelector('[data-testid="api-endpoint"]'),
    bearerToken: document.querySelector('[data-testid="bearer-token"]'),
    configStatus: document.querySelector('[data-testid="config-status"]'),
    registerButton: document.querySelector('[data-testid="register-token"]'),
    registerStatus: document.querySelector('[data-testid="register-status"]'),
    responseBody: document.querySelector('[data-testid="response-body"]'),
    tokenField: document.querySelector('[data-testid="fcm-token"]'),
    tokenStatus: document.querySelector('[data-testid="token-status"]'),
  };

  const state = {
    token: '',
  };

  function setText(element, message) {
    if (element) {
      element.textContent = message;
    }
  }

  function setValue(element, value) {
    if (element) {
      element.value = value;
    }
  }

  function setToken(token, source) {
    state.token = token;
    setValue(elements.tokenField, token);
    setText(elements.tokenStatus, `Token acquired via ${source}.`);
  }

  function isMockMode() {
    return query.get('mock') === '1';
  }

  function readMockToken() {
    return query.get('mockToken') || 'playwright_browser_token_1234567890';
  }

  function readWebConfig() {
    const webConfig = config.webConfig ?? {};

    return {
      apiKey: webConfig.apiKey ?? '',
      authDomain: webConfig.authDomain ?? '',
      projectId: webConfig.projectId ?? '',
      storageBucket: webConfig.storageBucket ?? '',
      messagingSenderId: webConfig.messagingSenderId ?? '',
      appId: webConfig.appId ?? '',
      measurementId: webConfig.measurementId ?? '',
    };
  }

  function missingFirebaseConfig(webConfig, vapidKey) {
    const required = [
      ['apiKey', webConfig.apiKey],
      ['authDomain', webConfig.authDomain],
      ['projectId', webConfig.projectId],
      ['storageBucket', webConfig.storageBucket],
      ['messagingSenderId', webConfig.messagingSenderId],
      ['appId', webConfig.appId],
      ['vapidKey', vapidKey],
    ];

    return required
      .filter(([, value]) => typeof value !== 'string' || value.trim() === '')
      .map(([key]) => key);
  }

  async function acquireRealFirebaseToken() {
    const webConfig = readWebConfig();
    const vapidKey = typeof config.vapidKey === 'string' ? config.vapidKey : '';
    const missing = missingFirebaseConfig(webConfig, vapidKey);

    if (missing.length > 0) {
      throw new Error(`Firebase web config is incomplete. Missing: ${missing.join(', ')}`);
    }

    if (!('Notification' in window)) {
      throw new Error('This browser does not support the Notification API.');
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      throw new Error(`Notification permission was not granted. Current permission: ${permission}`);
    }

    if (!('serviceWorker' in navigator)) {
      throw new Error('This browser does not support service workers.');
    }

    const [{ initializeApp }, { getMessaging, getToken }] = await Promise.all([
      import('https://www.gstatic.com/firebasejs/10.12.5/firebase-app.js'),
      import('https://www.gstatic.com/firebasejs/10.12.5/firebase-messaging.js'),
    ]);

    const serviceWorkerRegistration = await navigator.serviceWorker.register(
      config.serviceWorkerPath || '/firebase-messaging-sw.js',
    );

    const app = initializeApp(webConfig);
    const messaging = getMessaging(app);
    const token = await getToken(messaging, {
      vapidKey,
      serviceWorkerRegistration,
    });

    if (typeof token !== 'string' || token.trim() === '') {
      throw new Error('Firebase returned an empty browser token.');
    }

    return token;
  }

  async function acquireToken() {
    setText(elements.tokenStatus, 'Requesting browser token...');
    setText(elements.configStatus, '');

    try {
      if (isMockMode()) {
        const token = readMockToken();
        setToken(token, 'mock Firebase test mode');
        setText(elements.configStatus, 'Mock mode is active. No real Firebase request was made.');
        return;
      }

      const token = await acquireRealFirebaseToken();
      setToken(token, 'real Firebase web messaging');
      setText(elements.configStatus, 'Real Firebase mode is active.');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown token acquisition error.';
      setText(elements.tokenStatus, message);
    }
  }

  async function registerToken() {
    const token = typeof elements.tokenField?.value === 'string' ? elements.tokenField.value.trim() : state.token;
    const endpoint = typeof elements.apiEndpoint?.value === 'string' ? elements.apiEndpoint.value.trim() : '';
    const bearerToken = typeof elements.bearerToken?.value === 'string' ? elements.bearerToken.value.trim() : '';

    if (token === '') {
      setText(elements.registerStatus, 'Acquire a browser token first.');
      return;
    }

    if (endpoint === '') {
      setText(elements.registerStatus, 'Enter an API endpoint before registering the token.');
      return;
    }

    setText(elements.registerStatus, 'Registering token with backend...');
    setText(elements.responseBody, '');

    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    };

    if (bearerToken !== '') {
      headers.Authorization = `Bearer ${bearerToken}`;
    }

    try {
      const response = await fetch(endpoint, {
        method: 'PUT',
        headers,
        body: JSON.stringify({ fcmToken: token }),
      });

      const rawBody = await response.text();
      setText(elements.responseBody, rawBody);

      if (!response.ok) {
        setText(elements.registerStatus, `Token registration failed with status ${response.status}.`);
        return;
      }

      setText(elements.registerStatus, 'Token registered successfully.');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unknown token registration error.';
      setText(elements.registerStatus, message);
    }
  }

  elements.acquireButton?.addEventListener('click', () => {
    void acquireToken();
  });

  elements.registerButton?.addEventListener('click', () => {
    void registerToken();
  });

  setValue(elements.apiEndpoint, config.registerEndpoint || '/api/v1/user/notifications/token');
  setText(
    elements.configStatus,
    isMockMode()
      ? 'Mock mode is active. Add ?mock=0 or remove the query parameter for real Firebase mode.'
      : 'Real Firebase mode requires the Firebase web env keys plus FIREBASE_WEB_VAPID_KEY.',
  );

  if (query.get('auto') === '1') {
    void acquireToken();
  }
})();
