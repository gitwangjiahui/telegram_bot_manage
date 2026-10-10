package verification

import (
	"strconv"
	"strings"
)

func formatID(id int64) string {
	return strconv.FormatInt(id, 10)
}

func joinName(first, last string) string {
	return strings.TrimSpace(strings.TrimSpace(first) + " " + strings.TrimSpace(last))
}
