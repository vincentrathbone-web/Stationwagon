// A song's copyright details for listeners (round 45): the player and the embed show a line under
// the title ("© 2024 Owner · Written by …") and, on a click, the full details with the notes. The
// notes arrive cleaned by the server (text, line breaks, links); they're rebuilt here node by node
// all the same, so a link is only ever http, https or mailto and nothing else can get in.
(() => {
  const SAFE = /^(https?:\/\/[^\s<>"']+|mailto:[^\s<>"'@]+@[^\s<>"']+)$/i;

  /** The notes' HTML as text, line breaks and safe links, appended to `into`. */
  function notes(into, html) {
    const doc = new DOMParser().parseFromString("<body>" + html + "</body>", "text/html");
    const walk = (from, to) => {
      for (const n of from.childNodes) {
        if (n.nodeType === 3) to.appendChild(document.createTextNode(n.nodeValue));
        else if (n.nodeName === "BR") to.appendChild(document.createElement("br"));
        else if (n.nodeName === "A") {
          const href = (n.getAttribute("href") || "").trim();
          if (SAFE.test(href)) {
            const a = document.createElement("a");
            a.href = href; a.target = "_blank"; a.rel = "noopener noreferrer";
            walk(n, a);
            to.appendChild(a);
          } else walk(n, to);
        } else if (n.nodeType === 1 && !/^(SCRIPT|STYLE)$/.test(n.nodeName)) walk(n, to);
      }
    };
    walk(doc.body, into);
  }

  /** The full details as a fragment: who owns it, the licence, publisher, writers, then the notes. */
  function details(r) {
    const frag = document.createDocumentFragment();
    const dl = document.createElement("dl");
    dl.className = "rights-list";
    const row = (label, value) => {
      if (!value) return;
      const dt = document.createElement("dt"), dd = document.createElement("dd");
      dt.textContent = label; dd.textContent = value;
      dl.append(dt, dd);
    };
    row("Copyright", ((r.year ? r.year + " " : "") + (r.owner || "")).trim() && "© " + ((r.year ? r.year + " " : "") + (r.owner || "")).trim());
    row("Licence", r.licence);
    row("Publishing house", r.publisher);
    row("Songwriter(s)", r.writers);
    if (dl.children.length) frag.appendChild(dl);
    if (r.notes) {
      const p = document.createElement("p");
      p.className = "rights-notes";
      notes(p, r.notes);
      frag.appendChild(p);
    }
    return frag;
  }

  window.SwRights = { details, notes };
})();
