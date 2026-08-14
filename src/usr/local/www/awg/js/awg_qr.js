/*
 * awg_qr.js
 *
 * part of pfSense-pkg-AmneziaWG (https://github.com/MarceloMayo74/pfsense-amneziawg)
 * Copyright (c) 2026 Marcelo Mayo
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * Dibujar y descargar el QR de un archivo de cliente.
 *
 * Vive aparte porque lo usan DOS paginas: la de edicion de un peer, que muestra
 * el codigo, y la lista, donde el icono de QR lo descarga. Tenerlo dos veces
 * significaria arreglar cada cosa dos veces.
 *
 * Necesita la libreria QRCode cargada antes. Los tamanos y el nivel de
 * correccion los pisa cada pagina desde el globals del paquete.
 */

var awgQr = {
	// Los pisa la pagina; estos son los mismos que trae awg_globals.inc
	size: 512,
	displaySize: 400,
	quietZone: 4,
	level: 'L',

	/*
	 * Arregla tres cosas que la libreria deja mal en el <svg> que arma:
	 *
	 *  - usa width="100%" height="100%" e ignora el tamano que se le pidio, asi
	 *    que adentro de un contenedor sin altura el codigo colapsa a nada
	 *  - no deja zona tranquila, y el estandar pide cuatro modulos vacios de
	 *    cada lado para que un lector encuentre el codigo
	 *  - omite el xmlns, con lo cual una copia serializada no carga como imagen
	 */
	fixSvg: function(holder, size) {
		var svg = holder.querySelector('svg');

		if (!svg) {
			return null;
		}

		var box = (svg.getAttribute('viewBox') || '').split(/\s+/);
		var modules = (box.length === 4) ? parseInt(box[2], 10) : 0;

		if (modules > 0) {
			var quiet = this.quietZone;
			var side = modules + (quiet * 2);

			svg.setAttribute('viewBox', (-quiet) + ' ' + (-quiet) + ' ' + side + ' ' + side);

			// El rect de fondo tiene que cubrir tambien el margen nuevo
			for (var i = 0; i < svg.childNodes.length; i++) {
				var node = svg.childNodes[i];

				if (node.nodeName && (node.nodeName.toLowerCase() === 'rect')) {
					node.setAttribute('x', -quiet);
					node.setAttribute('y', -quiet);
					node.setAttribute('width', side);
					node.setAttribute('height', side);

					break;
				}
			}
		}

		svg.setAttribute('width', size);
		svg.setAttribute('height', size);

		// Achicarse en pantallas angostas en vez de desbordar la columna
		svg.setAttribute('style', 'max-width: 100%; height: auto;');

		if (!svg.getAttribute('xmlns')) {
			svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
		}

		return svg;
	},

	svgDataUrl: function(svg) {
		var text = new XMLSerializer().serializeToString(svg);

		return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(text)));
	},

	// Le pasa una data URL al navegador como descarga
	save: function(dataUrl, filename) {
		var link = document.createElement('a');

		link.href = dataUrl;
		link.download = filename;

		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	},

	// Dibuja el codigo adentro de un elemento, para mirarlo en pantalla
	render: function(elementId, text) {
		var holder = document.getElementById(elementId);

		if (!holder || (typeof QRCode === 'undefined')) {
			return false;
		}

		holder.innerHTML = '';

		new QRCode(holder, {
			text: text,
			width: this.displaySize,
			height: this.displaySize,
			correctLevel: QRCode.CorrectLevel[this.level]
		});

		return this.fixSvg(holder, this.displaySize) !== null;
	},

	/*
	 * Descarga el codigo como PNG.
	 *
	 * Un SVG pelado se reescala a toda la ventana al abrirlo, asi que se
	 * rasteriza a un tamano fijo. El SVG queda de respaldo para los navegadores
	 * que se nieguen a dibujarlo en un canvas.
	 */
	download: function(text, filename) {
		var self = this;

		if (typeof QRCode === 'undefined') {
			return false;
		}

		// Un contenedor propio y oculto, para no pisar el que se esta viendo
		var holder = document.getElementById('awg_qr_hidden');

		if (!holder) {
			holder = document.createElement('div');
			holder.id = 'awg_qr_hidden';
			holder.style.display = 'none';

			document.body.appendChild(holder);
		}

		holder.innerHTML = '';

		new QRCode(holder, {
			text: text,
			width: this.size,
			height: this.size,
			correctLevel: QRCode.CorrectLevel[this.level]
		});

		var svg = this.fixSvg(holder, this.size);

		if (!svg) {
			return false;
		}

		var svgUrl = this.svgDataUrl(svg);
		var img = new Image();

		img.onload = function() {
			try {
				var canvas = document.createElement('canvas');

				canvas.width = self.size;
				canvas.height = self.size;

				var ctx = canvas.getContext('2d');

				// Fondo blanco, para que el codigo se lea en visores oscuros
				ctx.fillStyle = '#ffffff';
				ctx.fillRect(0, 0, self.size, self.size);
				ctx.drawImage(img, 0, 0, self.size, self.size);

				self.save(canvas.toDataURL('image/png'), filename + '.png');
			} catch (e) {
				self.save(svgUrl, filename + '.svg');
			}

			holder.innerHTML = '';
		};

		img.onerror = function() {
			self.save(svgUrl, filename + '.svg');

			holder.innerHTML = '';
		};

		img.src = svgUrl;

		return true;
	}
};
