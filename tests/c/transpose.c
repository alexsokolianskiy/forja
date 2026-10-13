#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>

int64_t *{{symbol}}(const int64_t *m, size_t m_rows, size_t m_cols, int64_t *forja_rows, int64_t *forja_cols, int32_t *forja_err)
{
    *forja_rows = 0;
    *forja_cols = 0;
    if (m_rows == 0 || m_cols == 0) {
        return NULL; /* PHP returns [] for both an empty matrix and a matrix of empty rows */
    }
    int64_t *out = malloc(m_rows * m_cols * sizeof(int64_t));
    if (out == NULL) {
        *forja_err = 1;
        return NULL;
    }
    for (size_t i = 0; i < m_rows; i++) {
        for (size_t j = 0; j < m_cols; j++) {
            out[j * m_rows + i] = m[i * m_cols + j];
        }
    }
    *forja_rows = (int64_t) m_cols;
    *forja_cols = (int64_t) m_rows;
    return out;
}
