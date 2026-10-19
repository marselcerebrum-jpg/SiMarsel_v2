/**
 * Ekspor tabel di sisi browser. `columns` berisi { header, value(row), type? }
 * dengan type 'number' untuk kolom angka.
 */

function download(blob, fileName) {
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

            return column.type === 'number' ? { value: Number(value), type: Number, format: '#,##0' } : { value: String(value ?? '') };
        }),
    );

    const blob = await writeExcelFile([header, ...body], {
        columns: columns.map((column) => ({ width: column.width ?? 16 })),
    }).toBlob();

    download(blob, `${fileName}.xlsx`);
}
