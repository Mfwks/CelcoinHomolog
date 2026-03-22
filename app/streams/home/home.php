<!doctype html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Microframeworks.com">
  <meta name="author" content="Microframeworks">
  <title>Microframeworks.com</title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#af5ebd">
  <meta name="generator" content="Microframeworks">
  <link rel="icon" href="https://microframeworks.com/tools/ups/icon.png">

  <style>
    @charset "UTF-8";

    :root {
      --bs-blue: #0d6efd;
      --bs-indigo: #6610f2;
      --bs-purple: #6f42c1;
      --bs-pink: #d63384;
      --bs-red: #dc3545;
      --bs-orange: #fd7e14;
      --bs-yellow: #ffc107;
      --bs-green: #198754;
      --bs-teal: #20c997;
      --bs-cyan: #0dcaf0;
      --bs-white: #fff;
      --bs-gray: #6c757d;
      --bs-gray-dark: #343a40;
      --bs-gray-100: #f8f9fa;
      --bs-gray-200: #e9ecef;
      --bs-gray-300: #dee2e6;
      --bs-gray-400: #ced4da;
      --bs-gray-500: #adb5bd;
      --bs-gray-600: #6c757d;
      --bs-gray-700: #495057;
      --bs-gray-800: #343a40;
      --bs-gray-900: #212529;
      --bs-primary: #0d6efd;
      --bs-secondary: #6c757d;
      --bs-success: #198754;
      --bs-info: #0dcaf0;
      --bs-warning: #ffc107;
      --bs-danger: #dc3545;
      --bs-light: #f8f9fa;
      --bs-dark: #212529;
      --bs-primary-rgb: 13, 110, 253;
      --bs-secondary-rgb: 108, 117, 125;
      --bs-success-rgb: 25, 135, 84;
      --bs-info-rgb: 13, 202, 240;
      --bs-warning-rgb: 255, 193, 7;
      --bs-danger-rgb: 220, 53, 69;
      --bs-light-rgb: 248, 249, 250;
      --bs-dark-rgb: 33, 37, 41;
      --bs-white-rgb: 255, 255, 255;
      --bs-black-rgb: 0, 0, 0;
      --bs-body-color-rgb: 33, 37, 41;
      --bs-body-bg-rgb: 255, 255, 255;
      --bs-font-sans-serif: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
      --bs-font-monospace: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      --bs-gradient: linear-gradient(180deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0));
      --bs-body-font-family: var(--bs-font-sans-serif);
      --bs-body-font-size: 1rem;
      --bs-body-font-weight: 400;
      --bs-body-line-height: 1.5;
      --bs-body-color: #212529;
      --bs-body-bg: #fff
    }

    *,
    ::after,
    ::before {
      box-sizing: border-box
    }

    @media (prefers-reduced-motion:no-preference) {
      :root {
        scroll-behavior: smooth
      }
    }

    body {
      margin: 0;
      font-family: var(--bs-body-font-family);
      font-size: var(--bs-body-font-size);
      font-weight: var(--bs-body-font-weight);
      line-height: var(--bs-body-line-height);
      color: var(--bs-body-color);
      text-align: var(--bs-body-text-align);
      background-color: var(--bs-body-bg);
      -webkit-text-size-adjust: 100%;
      -webkit-tap-highlight-color: transparent
    }

    .h1,
    .h2,
    .h3,
    .h4,
    .h5,
    .h6,
    h1,
    h2,
    h3,
    h4,
    h5,
    h6 {
      margin-top: 0;
      margin-bottom: .5rem;
      font-weight: 500;
      line-height: 1.2
    }

    .h1,
    h1 {
      font-size: calc(1.375rem + 1.5vw)
    }

    @media (min-width:1200px) {

      .h1,
      h1 {
        font-size: 2.5rem
      }
    }

    .h2,
    h2 {
      font-size: calc(1.325rem + .9vw)
    }

    @media (min-width:1200px) {

      .h2,
      h2 {
        font-size: 2rem
      }
    }

    .h3,
    h3 {
      font-size: calc(1.3rem + .6vw)
    }

    @media (min-width:1200px) {

      .h3,
      h3 {
        font-size: 1.75rem
      }
    }

    .h4,
    h4 {
      font-size: calc(1.275rem + .3vw)
    }

    @media (min-width:1200px) {

      .h4,
      h4 {
        font-size: 1.5rem
      }
    }

    .h5,
    h5 {
      font-size: 1.25rem
    }

    .h6,
    h6 {
      font-size: 1rem
    }

    p {
      margin-top: 0;
      margin-bottom: 1rem
    }

    b {
      font-weight: bolder
    }

    a {
      color: #0d6efd;
      text-decoration: underline
    }

    a:hover {
      color: #0a58ca
    }

    a:not([href]):not([class]),
    a:not([href]):not([class]):hover {
      color: inherit;
      text-decoration: none
    }

    img {
      vertical-align: middle
    }

    table {
      caption-side: bottom;
      border-collapse: collapse
    }

    [role=button] {
      cursor: pointer
    }

    [list]::-webkit-calendar-picker-indicator {
      display: none
    }

    [type=button],
    [type=reset],
    [type=submit] {
      -webkit-appearance: button
    }

    [type=button]:not(:disabled),
    [type=reset]:not(:disabled),
    [type=submit]:not(:disabled) {
      cursor: pointer
    }

    ::-moz-focus-inner {
      padding: 0;
      border-style: none
    }

    ::-webkit-datetime-edit-day-field,
    ::-webkit-datetime-edit-fields-wrapper,
    ::-webkit-datetime-edit-hour-field,
    ::-webkit-datetime-edit-minute,
    ::-webkit-datetime-edit-month-field,
    ::-webkit-datetime-edit-text,
    ::-webkit-datetime-edit-year-field {
      padding: 0
    }

    ::-webkit-inner-spin-button {
      height: auto
    }

    [type=search] {
      outline-offset: -2px;
      -webkit-appearance: textfield
    }

    ::-webkit-search-decoration {
      -webkit-appearance: none
    }

    ::-webkit-color-swatch-wrapper {
      padding: 0
    }

    ::file-selector-button {
      font: inherit
    }

    ::-webkit-file-upload-button {
      font: inherit;
      -webkit-appearance: button
    }

    [hidden] {
      display: none !important
    }

    .display-1 {
      font-size: calc(1.625rem + 4.5vw);
      font-weight: 300;
      line-height: 1.2
    }

    @media (min-width:1200px) {
      .display-1 {
        font-size: 5rem
      }
    }

    .display-2 {
      font-size: calc(1.575rem + 3.9vw);
      font-weight: 300;
      line-height: 1.2
    }

    @media (min-width:1200px) {
      .display-2 {
        font-size: 4.5rem
      }
    }

    .display-3 {
      font-size: calc(1.525rem + 3.3vw);
      font-weight: 300;
      line-height: 1.2
    }

    @media (min-width:1200px) {
      .display-3 {
        font-size: 4rem
      }
    }

    .display-4 {
      font-size: calc(1.475rem + 2.7vw);
      font-weight: 300;
      line-height: 1.2
    }

    @media (min-width:1200px) {
      .display-4 {
        font-size: 3.5rem
      }
    }

    .display-5 {
      font-size: calc(1.425rem + 2.1vw);
      font-weight: 300;
      line-height: 1.2
    }

    @media (min-width:1200px) {
      .display-5 {
        font-size: 3rem
      }
    }

    .display-6 {
      font-size: calc(1.375rem + 1.5vw);
      font-weight: 300;
      line-height: 1.2
    }

    @media (min-width:1200px) {
      .display-6 {
        font-size: 2.5rem
      }
    }

    .table {
      --bs-table-bg: transparent;
      --bs-table-accent-bg: transparent;
      --bs-table-striped-color: #212529;
      --bs-table-striped-bg: rgba(0, 0, 0, 0.05);
      --bs-table-active-color: #212529;
      --bs-table-active-bg: rgba(0, 0, 0, 0.1);
      --bs-table-hover-color: #212529;
      --bs-table-hover-bg: rgba(0, 0, 0, 0.075);
      width: 100%;
      margin-bottom: 1rem;
      color: #212529;
      vertical-align: top;
      border-color: #dee2e6
    }

    .table>:not(caption)>*>* {
      padding: .5rem .5rem;
      background-color: var(--bs-table-bg);
      border-bottom-width: 1px;
      box-shadow: inset 0 0 0 9999px var(--bs-table-accent-bg)
    }

    .table>:not(:last-child)>:last-child>* {
      border-bottom-color: currentColor
    }

    .table-danger {
      --bs-table-bg: #f8d7da;
      --bs-table-striped-bg: #eccccf;
      --bs-table-striped-color: #000;
      --bs-table-active-bg: #dfc2c4;
      --bs-table-active-color: #000;
      --bs-table-hover-bg: #e5c7ca;
      --bs-table-hover-color: #000;
      color: #000;
      border-color: #dfc2c4
    }

    .btn {
      display: inline-block;
      font-weight: 400;
      line-height: 1.5;
      color: #212529;
      text-align: center;
      text-decoration: none;
      vertical-align: middle;
      cursor: pointer;
      -webkit-user-select: none;
      -moz-user-select: none;
      user-select: none;
      background-color: transparent;
      border: 1px solid transparent;
      padding: .375rem .75rem;
      font-size: 1rem;
      border-radius: .25rem;
      transition: color .15s ease-in-out, background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out
    }

    @media (prefers-reduced-motion:reduce) {
      .btn {
        transition: none
      }
    }

    .btn:hover {
      color: #212529
    }

    .btn:focus {
      outline: 0;
      box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25)
    }

    .btn:disabled {
      pointer-events: none;
      opacity: .65
    }

    .btn-danger {
      color: #fff;
      background-color: #dc3545;
      border-color: #dc3545
    }

    .btn-danger:hover {
      color: #fff;
      background-color: #bb2d3b;
      border-color: #b02a37
    }

    .btn-danger:focus {
      color: #fff;
      background-color: #bb2d3b;
      border-color: #b02a37;
      box-shadow: 0 0 0 .25rem rgba(225, 83, 97, .5)
    }

    .btn-danger:active {
      color: #fff;
      background-color: #b02a37;
      border-color: #a52834
    }

    .btn-danger:active:focus {
      box-shadow: 0 0 0 .25rem rgba(225, 83, 97, .5)
    }

    .btn-danger:disabled {
      color: #fff;
      background-color: #dc3545;
      border-color: #dc3545
    }

    .btn-link {
      font-weight: 400;
      color: #0d6efd;
      text-decoration: underline
    }

    .btn-link:hover {
      color: #0a58ca
    }

    .btn-link:disabled {
      color: #6c757d
    }

    @-webkit-keyframes progress-bar-stripes {
      0% {
        background-position-x: 1rem
      }
    }

    @keyframes progress-bar-stripes {
      0% {
        background-position-x: 1rem
      }
    }

    @-webkit-keyframes spinner-border {
      to {
        transform: rotate(360deg)
      }
    }

    @keyframes spinner-border {
      to {
        transform: rotate(360deg)
      }
    }

    @-webkit-keyframes spinner-grow {
      0% {
        transform: scale(0)
      }

      50% {
        opacity: 1;
        transform: none
      }
    }

    @keyframes spinner-grow {
      0% {
        transform: scale(0)
      }

      50% {
        opacity: 1;
        transform: none
      }
    }

    @-webkit-keyframes placeholder-glow {
      50% {
        opacity: .2
      }
    }

    @keyframes placeholder-glow {
      50% {
        opacity: .2
      }
    }

    @-webkit-keyframes placeholder-wave {
      100% {
        -webkit-mask-position: -200% 0;
        mask-position: -200% 0
      }
    }

    @keyframes placeholder-wave {
      100% {
        -webkit-mask-position: -200% 0;
        mask-position: -200% 0
      }
    }

    .link-danger {
      color: #dc3545
    }

    .link-danger:focus,
    .link-danger:hover {
      color: #b02a37
    }

    .align-middle {
      vertical-align: middle !important
    }

    .opacity-0 {
      opacity: 0 !important
    }

    .opacity-25 {
      opacity: .25 !important
    }

    .opacity-50 {
      opacity: .5 !important
    }

    .opacity-75 {
      opacity: .75 !important
    }

    .opacity-100 {
      opacity: 1 !important
    }

    .h-25 {
      height: 25% !important
    }

    .h-50 {
      height: 50% !important
    }

    .h-75 {
      height: 75% !important
    }

    .h-100 {
      height: 100% !important
    }

    .h-auto {
      height: auto !important
    }

    .align-content-center {
      align-content: center !important
    }

    .me-0 {
      margin-right: 0 !important
    }

    .me-1 {
      margin-right: .25rem !important
    }

    .me-2 {
      margin-right: .5rem !important
    }

    .me-3 {
      margin-right: 1rem !important
    }

    .me-4 {
      margin-right: 1.5rem !important
    }

    .me-5 {
      margin-right: 3rem !important
    }

    .me-auto {
      margin-right: auto !important
    }

    .p-0 {
      padding: 0 !important
    }

    .p-1 {
      padding: .25rem !important
    }

    .p-2 {
      padding: .5rem !important
    }

    .p-3 {
      padding: 1rem !important
    }

    .p-4 {
      padding: 1.5rem !important
    }

    .p-5 {
      padding: 3rem !important
    }

    .px-0 {
      padding-right: 0 !important;
      padding-left: 0 !important
    }

    .px-1 {
      padding-right: .25rem !important;
      padding-left: .25rem !important
    }

    .px-2 {
      padding-right: .5rem !important;
      padding-left: .5rem !important
    }

    .px-3 {
      padding-right: 1rem !important;
      padding-left: 1rem !important
    }

    .px-4 {
      padding-right: 1.5rem !important;
      padding-left: 1.5rem !important
    }

    .px-5 {
      padding-right: 3rem !important;
      padding-left: 3rem !important
    }

    .pt-0 {
      padding-top: 0 !important
    }

    .pt-1 {
      padding-top: .25rem !important
    }

    .pt-2 {
      padding-top: .5rem !important
    }

    .pt-3 {
      padding-top: 1rem !important
    }

    .pt-4 {
      padding-top: 1.5rem !important
    }

    .pt-5 {
      padding-top: 3rem !important
    }

    .text-center {
      text-align: center !important
    }

    .text-decoration-none {
      text-decoration: none !important
    }

    .text-decoration-underline {
      text-decoration: underline !important
    }

    .text-danger {
      --bs-text-opacity: 1;
      color: rgba(var(--bs-danger-rgb), var(--bs-text-opacity)) !important
    }

    .text-body {
      --bs-text-opacity: 1;
      color: rgba(var(--bs-body-color-rgb), var(--bs-text-opacity)) !important
    }

    .text-opacity-25 {
      --bs-text-opacity: 0.25
    }

    .text-opacity-50 {
      --bs-text-opacity: 0.5
    }

    .text-opacity-75 {
      --bs-text-opacity: 0.75
    }

    .text-opacity-100 {
      --bs-text-opacity: 1
    }

    * {
      line-height: 1.2;
      margin: 0
    }

    html {
      color: #888;
      display: table;
      font-family: sans-serif;
      height: 100%;
      text-align: center;
      width: 100%
    }

    body {
      display: table-cell;
      vertical-align: middle;
      margin: 2em auto
    }

    h1 {
      color: #555;
      font-size: 2em;
      font-weight: 400
    }

    p {
      margin: 0 auto;
      width: 300px
    }

    a {
      color: #007bff;
      font-family: "Segoe UI";
      text-decoration: none
    }

    img {
      filter: grayscale(0)
    }

    @media only screen and (max-width:280px) {

      body,
      p {
        width: 95%
      }

      h1 {
        font-size: 1.5em;
        margin: 0 0 .3em
      }
    }

    .blink_me {
      animation: blinker 1s linear infinite
    }

    @keyframes blinker {
      50% {
        opacity: 0
      }
    }

    img {
      filter: grayscale(0%);
    }

    a {
      color: #555
    }
  </style>
</head>

<body>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <h1>Code::Base</h1>
  <p>&nbsp;</p>
  <p>&nbsp;</p>
  <p><a href="https://microframeworks.com/">Microframeworks &copy;</a></p>
  <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('sw.js');
    }
  </script>
</body>

</html>