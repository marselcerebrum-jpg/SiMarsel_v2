/**
 * Ekspor tabel di sisi browser. `columns` berisi { header, value(row), type?, excelFormat? }
 * dengan type 'number' untuk kolom angka; excelFormat menimpa format angka bawaan '#,##0'.
 */

export function download(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const link = Object.assign(document.createElement('a'), { href: url, download: fileName });

    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

function csvCell(value) {
    const text = String(value ?? '');

    return /[",\n\r]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

export function exportCsv(fileName, columns, rows) {
    const lines = [
        columns.map((column) => csvCell(column.header)),
        ...rows.map((row) => columns.map((column) => csvCell(column.value(row)))),
    ].map((cells) => cells.join(','));

    // BOM agar Excel membaca file sebagai UTF-8.
    download(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), `${fileName}.csv`);
}

export async function exportExcel(fileName, columns, rows) {
    // Dimuat saat dibutuhkan saja agar tidak memperberat halaman.
    const { default: writeExcelFile } = await import('write-excel-file/browser');

    const header = columns.map((column) => ({ value: column.header, fontWeight: 'bold' }));
    const body = rows.map((row) =>
        columns.map((column) => {
            const value = column.value(row);

            return column.type === 'number' ? { value: Number(value), type: Number, format: column.excelFormat ?? '#,##0' } : { value: String(value ?? '') };
        }),
    );

    const blob = await writeExcelFile([header, ...body], {
        columns: columns.map((column) => ({ width: column.width ?? 16 })),
    }).toBlob();

    download(blob, `${fileName}.xlsx`);
}

/**
 * Decimal separator of the browser language, e.g. "," for id-ID. A spreadsheet in that language
 * reads "46,67" as a number but "46.67" as text, and the other way round for en-US.
 */
function browserDecimalSeparator() {
    if (typeof navigator === 'undefined' || !navigator.language) return '.';

    return new Intl.NumberFormat(navigator.language).formatToParts(1.5).find(({ type }) => type === 'decimal')?.value ?? '.';
}

function tsvCell(value, decimal) {
    if (typeof value === 'number' && !Number.isInteger(value)) return String(value).replace('.', decimal);

    // A tab or line break inside a value would split it into another cell or row when pasted.
    return String(value ?? '').replace(/[\t\r\n]+/g, ' ');
}

/**
 * Tab-separated rows with a header row, which spreadsheets paste into separate columns.
 * Numbers stay unformatted (no Rp, no thousand separators); decimals use the browser's separator.
 */
export function toTsv(columns, rows, { decimal = browserDecimalSeparator() } = {}) {
    return [
        columns.map((column) => tsvCell(column.header, decimal)),
        ...rows.map((row) => columns.map((column) => tsvCell(column.value(row), decimal))),
    ].map((cells) => cells.join('\t')).join('\n');
}

/**
 * Copies text, or a promise of text, to the clipboard. A promise goes through ClipboardItem so the
 * copy keeps the click's permission while the data is still loading. The Clipboard API needs a
 * secure context (HTTPS or localhost), so plain HTTP falls back to a hidden textarea.
 */
export async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        if (typeof text !== 'string' && navigator.clipboard.write && window.ClipboardItem) {
            const blob = Promise.resolve(text).then((value) => new Blob([value], { type: 'text/plain' }));
            await navigator.clipboard.write([new ClipboardItem({ 'text/plain': blob })]);
            return;
        }

        await navigator.clipboard.writeText(await text);
        return;
    }

    const textarea = Object.assign(document.createElement('textarea'), { value: await text, readOnly: true });
    textarea.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.append(textarea);
    textarea.select();

    const copied = document.execCommand('copy');
    textarea.remove();

    if (!copied) throw new Error('Clipboard is not available.');
}
